<?php
// This file is part of the local_mcpchat plugin for Moodle.
namespace local_mcpchat;

defined('MOODLE_INTERNAL') || die();

/**
 * Orquestador de un turno del chat del bot.
 *
 * Flujo: permiso -> limite diario -> pendiente de confirmacion ->
 * contexto (ultimos N mensajes) -> DeepSeek con tools -> ejecucion
 * (con confirmacion para riesgosas) -> auditoria -> respuesta.
 * Max 5 tool_calls por turno.
 */
class bot {

    const MAX_ITERACIONES = 5;

    /** @var int */
    protected $conversationid;
    /** @var int */
    protected $senderid;
    /** @var int */
    protected $botid;

    public function __construct(int $conversationid, int $senderid, int $botid) {
        $this->conversationid = $conversationid;
        $this->senderid = $senderid;
        $this->botid = $botid;
    }

    /**
     * Ejecuta el turno del bot para el mensaje que disparo la adhoc task.
     *
     * @param int $messageid Id del mensaje del remitente que disparo el
     *                       turno (el ultimo de la conversacion; el
     *                       observer lo actualiza si llegan varios antes
     *                       del cron). 0 = sin referencia.
     */
    public function run(int $messageid = 0): void {
        // Puerta de entrada (re-chequeo en cron: la capability pudo cambiar).
        if (!has_capability('local/mcpchat:use', \context_system::instance(),
                $this->senderid)) {
            return;
        }
        if (!$this->bajo_limite_diario()) {
            $this->enviar(get_string('limite_diario', 'local_mcpchat'));
            return;
        }
        // Un mensaje "si/no" resuelve la confirmacion pendiente sin LLM.
        if ($this->resolver_pendiente($messageid)) {
            return;
        }
        $apikey = (string) get_config('local_mcpchat', 'apikey');
        if ($apikey === '') {
            $this->enviar(get_string('bot_no_configurado', 'local_mcpchat'));
            return;
        }
        $model = get_config('local_mcpchat', 'model') ?: 'deepseek-v4-flash';

        $client = new deepseek();
        $mensajes = $this->historial();
        $tools = $this->tools_activas();
        $turnos = 0;

        try {
            while ($turnos < self::MAX_ITERACIONES) {
                $turnos++;
                $resp = $client->chat($mensajes, $tools, $apikey, $model);

                if (empty($resp['tool_calls'])) {
                    $texto = trim((string) ($resp['content'] ?? ''));
                    if ($texto === '') {
                        $texto = get_string('respuesta_vacia', 'local_mcpchat');
                    }
                    $this->enviar($texto);
                    return;
                }

                // El modelo quiere ejecutar tools: eco del assistant + resultados.
                $mensajes[] = array('role' => 'assistant',
                    'content' => $resp['content'] ?? null,
                    'tool_calls' => $resp['tool_calls']);
                foreach ($resp['tool_calls'] as $tc) {
                    $name = $tc['function']['name'];
                    $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: array();

                    if (dispatcher::es_riesgosa($name)) {
                        // La pendiente guarda los argumentos REALES (se
                        // ejecutan tal cual al confirmar); el texto que ve
                        // el usuario y la auditoria usan la version
                        // redactada (passwords nunca en claro).
                        $this->guardar_pendiente($name, $args);
                        $this->enviar($this->texto_confirmacion($name, $this->redactar($args)));
                        return;
                    }
                    $resultado = $this->ejecutar($name, $args);
                    $mensajes[] = array('role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => json_encode($resultado, JSON_UNESCAPED_UNICODE));
                }
            }
        } catch (\moodle_exception $e) {
            // Error de DeepSeek (red, HTTP, schema invalido, ...): NO en
            // silencio — el usuario ve el error (las strings deepseek_*
            // llevan el detalle legible en {$a}).
            $this->enviar($e->getMessage());
            return;
        }
        $this->enviar(get_string('demasiadas_ops', 'local_mcpchat'));
    }

    protected function enviar(string $texto): void {
        \core_message\api::send_message_to_conversation(
            $this->botid, $this->conversationid,
            $this->formatear_html($texto), FORMAT_HTML);
    }

    /**
     * Convierte la salida del modelo a HTML limpio para el chat.
     *
     * El drawer renderiza el texto segun el FORMAT del mensaje: con
     * FORMAT_MOODLE el markdown que produce DeepSeek queda como texto
     * plano feo. Aqui se normaliza: si el texto ya es HTML (p. ej. las
     * confirmaciones de bot.php) solo se limpia; si es markdown residual
     * (negritas, listas, codigo), se convierte con markdown_a_html(). El
     * resultado pasa por clean_text (HTMLPurifier trusted=false: conserva
     * p/ul/li/b/h4, elimina scripts).
     */
    protected function formatear_html(string $texto): string {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return $texto;
        }
        if (preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $texto)) {
            // El modelo emite HTML directo (p. ej. <table>) cuando el prompt
            // le pide HTML: aplicarle las mismas reglas que a la via
            // markdown — nunca mostrar ids internos (columna ID y "(ID N)")
            // aunque el modelo los emita.
            $texto = preg_replace('/\s*\(ID\s+\d+\)/i', '', $texto);
            $html = self::limpiar_tablas_html($texto);
        } else {
            $html = $this->markdown_a_html($texto);
        }
        $limpio = clean_text($html, FORMAT_HTML);
        return $limpio === '' ? $texto : $limpio;
    }

    /**
     * Tablas que el modelo emite YA como HTML (<table> directo, permitido
     * por el prompt): la misma regla que tabla_a_html() — se omiten las
     * columnas cuyo encabezado es "ID" (ids internos, queja de UX de
     * Task 7). Se aplica a cada <table> del HTML.
     */
    protected static function limpiar_tablas_html(string $html): string {
        return preg_replace_callback('/<table[^>]*>.*?<\/table>/is', function ($m) {
            return self::limpiar_tabla_html($m[0]);
        }, $html);
    }

    protected static function limpiar_tabla_html(string $tabla): string {
        if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tabla, $fmatch)) {
            return $tabla;
        }
        $filas = $fmatch[1];
        $skip = array();
        if ($filas && preg_match_all('/<t[hH][^>]*>(.*?)<\/t[hH]>/is', $filas[0], $hmatch)) {
            foreach ($hmatch[1] as $j => $h) {
                if (strtolower(trim(strip_tags($h))) === 'id') {
                    $skip[] = $j;
                }
            }
        }
        if (!$skip) {
            return $tabla;
        }
        $abre = '<table>';
        if (preg_match('/<table[^>]*>/i', $tabla, $tmatch)) {
            $abre = $tmatch[0];
        }
        $out = $abre;
        foreach ($filas as $f) {
            if (!preg_match_all('/<t[hHdD][^>]*>.*?<\/t[hHdD]>/is', $f, $cmatch)) {
                $out .= "<tr>$f</tr>";
                continue;
            }
            $celdas = array();
            foreach ($cmatch[0] as $j => $c) {
                if (!in_array($j, $skip, true)) {
                    $celdas[] = $c;
                }
            }
            $out .= '<tr>' . implode('', $celdas) . '</tr>';
        }
        return $out . '</table>';
    }

    /**
     * Mini-conversor markdown -> HTML (red de seguridad para residuos del
     * modelo; el prompt pide HTML directamente): bloques de tabla |a|b|,
     * encabezados ##, bloques de lista -/1., negritas, italicas, codigo y
     * enlaces. No cubre todo markdown — es un fallback, no un parser.
     */
    protected function markdown_a_html(string $texto): string {
        // Los bloques de tabla se separan ANTES del conversor de lineas:
        // caerian como tuberias crudas dentro de un <p> (visto en el e2e
        // t6: el modelo lista cursos con tabla markdown aunque el prompt
        // pida HTML).
        $partes = preg_split('/((?:^[ \t]*\|.*[ \t]*$[\r\n]*)+)/m', $texto,
            -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($partes as $i => $parte) {
            if ($parte === '') {
                continue;
            }
            $out .= ($i % 2 === 1)
                ? self::tabla_a_html($parte)
                : self::lineas_a_html($parte);
        }
        return $out;
    }

    /**
     * Bloque de lineas | a | b | -> <table>. Las filas separadoras
     * (|----|) se omiten; la primera fila es el encabezado (<th>).
     *
     * Los ids internos no se muestran al usuario (queja de UX de Task 7):
     * las columnas cuyo encabezado es "ID" se omiten por completo aunque
     * el modelo las emita (el prompt lo prohibe, pero no es fiable).
     */
    protected static function tabla_a_html(string $bloque): string {
        $filas = array();
        foreach (preg_split('/\r?\n/', trim($bloque)) as $fila) {
            $celdas = array_map('trim', explode('|', trim($fila, "| \t")));
            if ($celdas === array('')) {
                continue;
            }
            $todassep = true;
            foreach ($celdas as $c) {
                if (!preg_match('/^:?-{2,}:?$/', $c)) {
                    $todassep = false;
                    break;
                }
            }
            if ($todassep) {
                continue;
            }
            $filas[] = $celdas;
        }
        if (!$filas) {
            return '';
        }
        $skip = array();
        foreach ($filas[0] as $j => $h) {
            if (strtolower($h) === 'id') {
                $skip[] = $j;
            }
        }
        $html = '<table>';
        foreach ($filas as $i => $celdas) {
            $tag = ($i === 0) ? 'th' : 'td';
            $html .= '<tr>';
            foreach ($celdas as $j => $c) {
                if (in_array($j, $skip, true)) {
                    continue;
                }
                $html .= "<$tag>" . self::markdown_en_linea(s($c)) . "</$tag>";
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * Conversor de lineas que no son tabla: encabezados ##, bloques de
     * lista -/1., negritas, italicas, codigo, enlaces y parrafos.
     */
    protected static function lineas_a_html(string $texto): string {
        $texto = s($texto); // Escapar HTML residual del modelo.
        // Ids internos en prosa ("(ID 7)") no se muestran al usuario
        // (misma razon que la columna ID de las tablas).
        $texto = preg_replace('/\s*\(ID\s+\d+\)/i', '', $texto);
        $out = '';
        $enlista = false;
        $parrafo = false;
        foreach (preg_split('/\r?\n/', $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                if ($parrafo) {
                    $out .= '</p>';
                    $parrafo = false;
                }
                if ($enlista) {
                    $out .= '</ul>';
                    $enlista = false;
                }
                continue;
            }
            if (preg_match('/^(?:[-*]|\d+\.)\s+(.*)$/', $linea, $m)) {
                if (!$enlista) {
                    if ($parrafo) {
                        $out .= '</p>';
                        $parrafo = false;
                    }
                    $out .= '<ul>';
                    $enlista = true;
                }
                $out .= '<li>' . self::markdown_en_linea($m[1]) . '</li>';
                continue;
            }
            if ($enlista) {
                $out .= '</ul>';
                $enlista = false;
            }
            if (preg_match('/^##\s+(.+)$/', $linea, $m)) {
                if ($parrafo) {
                    $out .= '</p>';
                    $parrafo = false;
                }
                $out .= '<h4>' . self::markdown_en_linea($m[1]) . '</h4>';
                continue;
            }
            if (!$parrafo) {
                $out .= '<p>';
                $parrafo = true;
            } else {
                $out .= '<br>';
            }
            $out .= self::markdown_en_linea($linea);
        }
        if ($parrafo) {
            $out .= '</p>';
        }
        if ($enlista) {
            $out .= '</ul>';
        }
        return $out;
    }

    /**
     * Elementos en linea del mini-conversor: codigo (primero, para no tocar
     * su contenido), negritas, italicas y enlaces.
     */
    protected static function markdown_en_linea(string $texto): string {
        $texto = preg_replace('/`([^`]+)`/', '<code>$1</code>', $texto);
        $texto = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $texto);
        $texto = preg_replace('/\*([^*]+)\*/', '<i>$1</i>', $texto);
        $texto = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/',
            '<a href="$2">$1</a>', $texto);
        return $texto;
    }

    protected function historial(): array {
        $limite = (int) get_config('local_mcpchat', 'contextomsgs');
        if ($limite <= 0) {
            $limite = 20;
        }
        $msgs = \core_message\api::get_conversation_messages(
            $this->botid, $this->conversationid, 0, $limite, 'timecreated DESC');
        $turno = array();
        foreach (array_reverse($msgs['messages']) as $m) {
            $rol = ((int) $m->useridfrom === $this->senderid) ? 'user' : 'assistant';
            $turno[] = array('role' => $rol, 'content' => (string) $m->text);
        }
        $system = (string) get_config('local_mcpchat', 'systemprompt');
        if ($system !== '') {
            array_unshift($turno, array('role' => 'system', 'content' => $system));
        }
        return $turno;
    }

    protected function tools_activas(): array {
        $file = __DIR__ . '/../json/tools.json';
        if (!file_exists($file)) {
            return array();
        }
        $tools = json_decode((string) file_get_contents($file), true) ?: array();
        // Entradas corruptas en tools.json (no-array, sin name) no deben
        // tumbar el turno con un TypeError.
        return array_values(array_filter($tools, function ($t): bool {
            return is_array($t) && !empty($t['name'])
                && dispatcher::existe($t['name']);
        }));
    }

    /**
     * Si existe una confirmacion pendiente del remitente, el mensaje que
     * disparo el turno la resuelve (si/no) SIN llamar al LLM. Devuelve
     * true si la resolvio.
     *
     * @param int $messageid Id del mensaje del remitente (customdata de la
     *                       task; el observer lo actualiza al ultimo
     *                       mensaje cuando llegan varios antes del cron).
     */
    protected function resolver_pendiente(int $messageid = 0): bool {
        global $DB;
        $pend = $DB->get_record('local_mcpchat_pending', array(
            'userid' => $this->senderid,
            'conversationid' => $this->conversationid));
        if (!$pend) {
            return false;
        }
        if ((int) $pend->expires < time()) {
            $DB->delete_records('local_mcpchat_pending', array('id' => $pend->id));
            $this->enviar('La confirmación caducó; repite la petición.');
            return true;
        }
        $DB->delete_records('local_mcpchat_pending', array('id' => $pend->id));

        // El mensaje que disparo el turno decide (no "el ultimo de la
        // conversacion": el observador actualiza el messageid si llegan
        // mensajes rapidos antes del cron).
        $texto = '';
        if ($messageid > 0) {
            $msg = $DB->get_record('messages', array('id' => $messageid));
            if ($msg) {
                // OJO: get_record devuelve la fila cruda de {messages}, cuya
                // columna de texto es fullmessage (no hay 'text').
                $texto = (string) ($msg->fullmessage ?? '');
            }
        }
        // mb_strtolower con UTF-8: 'SÍ' debe confirmar, no cancelar.
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        if (in_array($texto, array('si', 'sí', 'yes', 'ok', 'dale', 'confirmo', 'adelante'))) {
            $args = json_decode((string) $pend->params, true) ?: array();
            $resultado = $this->ejecutar($pend->toolname, $args);
            if (isset($resultado['ok']) && empty($resultado['ok'])) {
                $this->enviar('❌ No se pudo ejecutar: '
                    . (string) ($resultado['error'] ?? 'error desconocido'));
            } else {
                $this->enviar('✅ Ejecutado: '
                    . $this->frase_resultado($pend->toolname));
            }
        } else {
            $this->enviar('Cancelado. ¿Necesitas algo más?');
        }
        return true;
    }

    protected function bajo_limite_diario(): bool {
        global $DB;
        $max = (int) get_config('local_mcpchat', 'maxmsgsday');
        if ($max <= 0) {
            return true;
        }
        $count = $DB->count_records_select('messages',
            'conversationid = :conv AND useridfrom = :uid AND timecreated >= :hoy',
            array('conv' => $this->conversationid, 'uid' => $this->senderid,
                  'hoy' => strtotime('today')));
        return $count < $max;
    }

    /**
     * Ejecuta una tool con auditoria. Devuelve el resultado (o el error,
     * para que el LLM lo explique al usuario).
     */
    protected function ejecutar(string $name, array $args): array {
        global $DB;
        try {
            $resultado = dispatcher::ejecutar($name, $args);
            $status = 'ok';
        } catch (\Throwable $e) {
            // El dispatcher relanza \Error a proposito (TypeError y
            // similares no se enmascaran): cualquier fallo debe quedar en
            // la auditoria, no matar el turno en silencio.
            $resultado = array('ok' => false, 'error' => $e->getMessage());
            $status = 'error';
        }
        $DB->insert_record('local_mcpchat_audit', array(
            'userid' => $this->senderid, 'toolname' => $name,
            'params' => json_encode($this->redactar($args), JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'message' => substr(json_encode($resultado, JSON_UNESCAPED_UNICODE), 0, 2000),
            'timecreated' => time()));
        return $resultado;
    }

    protected function guardar_pendiente(string $name, array $args): void {
        global $DB;
        $DB->delete_records('local_mcpchat_pending',
            array('userid' => $this->senderid));
        $DB->insert_record('local_mcpchat_pending', array(
            'userid' => $this->senderid,
            'conversationid' => $this->conversationid,
            'toolname' => $name,
            // Args REALES: resolver_pendiente() los ejecuta tal cual al
            // confirmar (una password redactada crearia un usuario con
            // password '********'). La redaccion solo aplica al texto de
            // confirmacion y a la auditoria.
            'params' => json_encode($args, JSON_UNESCAPED_UNICODE),
            'timecreated' => time(), 'expires' => time() + 3600));
    }

    /**
     * Reemplaza credenciales por asteriscos antes de persistir (auditoria
     * y confirmacion pendiente): la password de crear_usuario no debe
     * quedar en claro en la BD.
     *
     * @param array $args Argumentos de la tool (pueden ser los redactados).
     * @return array
     */
    protected function redactar(array $args): array {
        if (isset($args['password'])) {
            $args['password'] = '********';
        }
        return $args;
    }

    protected function texto_confirmacion(string $name, array $args): string {
        // HTML humanizado: etiquetas en espanol (nunca ids crudos ni JSON) y
        // fechas legibles. El chat usa FORMAT_HTML.
        $etiquetas = array(
            'asignacion_id' => 'Tarea',
            'estudiante_id' => 'Estudiante',
            'usuario_id' => 'Usuario',
            'curso_id' => 'Curso',
            'curso_origen_id' => 'Curso',
            'nota' => 'Nota',
            'nota_maxima' => 'Nota máxima',
            'comentario' => 'Comentario',
            'username' => 'Usuario',
            'password' => 'Contraseña',
            'email' => 'Email',
            'firstname' => 'Nombre',
            'lastname' => 'Apellido',
            'grupo_id' => 'Grupo',
            'rol' => 'Rol',
            'nombre' => 'Nombre',
            'nombre_nuevo' => 'Nombre',
            'shortname' => 'Nombre corto',
            'shortname_nuevo' => 'Nombre corto',
            'categoria_id' => 'Categoría',
            'calificaciones' => 'Calificaciones',
        );
        $items = array();
        foreach ($args as $clave => $valor) {
            if ($valor === '' || $valor === null) {
                continue;
            }
            if (is_array($valor)) {
                // Lote: solo el recuento (cada registro lleva sus ids).
                $valor = count($valor) . ' registro(s)';
            }
            $etiqueta = $etiquetas[$clave]
                ?? ucfirst(str_replace('_', ' ', (string) $clave));
            if (preg_match('/^(fecha|nueva_fecha)/', (string) $clave)
                    && ctype_digit((string) $valor)) {
                $valor = userdate((int) $valor);
            }
            $items[] = '<li><b>' . s($etiqueta) . ':</b> '
                . s((string) $valor) . '</li>';
        }
        return '<p>Voy a ejecutar <b>'
            . s(ucwords(str_replace('_', ' ', $name)))
            . '</b> con estos datos:</p><ul>' . implode('', $items)
            . '</ul><p>¿Confirmas? Responde <b>sí</b> para ejecutarlo o '
            . '<b>no</b> para cancelarlo.</p>';
    }

    /**
     * Frase humana del resultado de una tool: nunca vuelca el array crudo
     * del dispatcher (tiene ids y estructura interna).
     */
    protected function frase_resultado(string $toolname): string {
        $frases = array(
            'calificar_entrega' => 'Calificación guardada.',
            'calificar_lote' => 'Calificaciones guardadas en lote.',
            'crear_curso' => 'Curso creado.',
            'crear_tarea' => 'Tarea creada.',
            'crear_pagina' => 'Página creada.',
            'crear_recurso' => 'Recurso creado.',
            'crear_enlace' => 'Enlace creado.',
            'crear_etiqueta' => 'Etiqueta creada.',
            'clonar_curso' => 'Clonación iniciada (tardará unos minutos).',
            'crear_usuario' => 'Usuario creado.',
            'matricular_usuario' => 'Matriculación realizada.',
            'desmatricular_usuario' => 'Desmatriculación realizada.',
            'crear_grupo' => 'Grupo creado.',
            'agregar_a_grupo' => 'Usuario agregado al grupo.',
            'publicar_anuncio' => 'Anuncio publicado.',
            'responder_discusion' => 'Respuesta publicada.',
            'enviar_mensaje' => 'Mensaje enviado.',
            'crear_evento' => 'Evento creado.',
            'bloquear_entregas' => 'Entrega bloqueada.',
            'desbloquear_entregas' => 'Entrega desbloqueada.',
            'otorgar_prorroga' => 'Prórroga otorgada.',
            'revertir_a_borrador' => 'Entrega revertida a borrador.',
        );
        return $frases[$toolname] ?? 'Operación completada.';
    }
}
