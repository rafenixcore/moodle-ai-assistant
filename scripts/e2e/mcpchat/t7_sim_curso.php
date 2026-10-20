<?php
// Task 7 (escenario de simulacion): curso con 20 estudiantes, 4 tareas,
// entregas mixtas (a tiempo / tardias / sin entregar) y correcciones en
// lote. Las acciones que tienen tool se ejecutan con el dispatcher del bot
// (las MISMAS 34 tools del MCP, en proceso, con set_user(admin) = mismas
// capacidades que el token docente). Idempotente: si el curso/shortname,
// los usuarios o las tareas ya existen, se reutilizan.
//
// Uso: docker compose exec -T web php /var/www/html/local/seedscripts/e2e/mcpchat/t7_sim_curso.php
define('CLI_SCRIPT', true);
require_once('/var/www/html/public/config.php');
require_once($CFG->dirroot . '/user/lib.php');

$fails = 0;
function ok($cond, $label) {
    global $fails;
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        $fails++;
    }
}

/** Ejecuta una tool del MCP via el dispatcher; devuelve null y lo reporta si falla. */
function tool(string $name, array $args) {
    try {
        return \local_mcpchat\dispatcher::ejecutar($name, $args);
    } catch (\Throwable $e) {
        echo "TOOL_ERROR $name: " . $e->getMessage() . "\n";
        return null;
    }
}

// El admin ejecuta las tools (mismas capacidades que el token docente del MCP).
\core\session\manager::set_user(core_user::get_user_by_username('admin'));

$shortname = 'SIM-MCP2026';
$fullname = 'Simulación MCP 2026 - Flujo de calificaciones';
$nusers = 20;
$password = 'SimPass2026!';

// --- 1. Curso (idempotente) --------------------------------------------
$cid = (int) $DB->get_field('course', 'id', array('shortname' => $shortname));
if (!$cid) {
    $r = tool('crear_curso', array(
        'nombre' => $fullname, 'shortname' => $shortname,
        'descripcion' => 'Curso de simulacion para el flujo de calificaciones (e2e t7).',
    ));
    $cid = (int) ($r[0]['id'] ?? 0);
}
ok($cid > 0, "curso creado/reutilizado SIM-MCP2026 (id $cid)");

// --- 2. Estudiantes sim01..sim20 (idempotente) --------------------------
$uids = array();
for ($i = 1; $i <= $nusers; $i++) {
    $uname = sprintf('sim%02d', $i);
    $uid = (int) $DB->get_field('user', 'id', array('username' => $uname));
    if (!$uid) {
        $r = tool('crear_usuario', array(
            'username' => $uname, 'password' => $password,
            'firstname' => 'Estudiante', 'lastname' => 'Sim ' . sprintf('%02d', $i),
            'email' => "$uname@example.local"));
        $uid = (int) ($r[0]['id'] ?? 0);
    }
    $uids[$i] = $uid;
}
$creados = count(array_filter($uids));
ok($creados === $nusers, "estudiantes listos ($creados/$nusers)");

// --- 3. Matricula student en el curso (via tool) ------------------------
$matriculados = 0;
foreach ($uids as $uid) {
    if (!$uid || $DB->record_exists('user_enrolments', array('userid' => $uid))
            && $DB->record_exists_sql(
                "SELECT 1 FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE ue.userid = :u AND e.courseid = :c",
                array('u' => $uid, 'c' => $cid))) {
        continue;
    }
    $r = tool('matricular_usuario', array('curso_id' => $cid, 'usuario_id' => $uid, 'rol' => 'student'));
    if ($r !== null) {
        $matriculados++;
    }
}
echo "INFO: matriculados ahora: $matriculados (el resto ya lo estaba)\n";

// --- 4. Tareas (via tool, idempotente por nombre) ------------------------
$tareas = array(
    array('nombre' => 'Informe inicial',      'dias' => -8,  'inst' => 'Informe de diagnostico del entorno.'),
    array('nombre' => 'Proyecto parcial',     'dias' => 6,   'inst' => 'Entrega parcial del proyecto.'),
    array('nombre' => 'Cuestionario de repaso', 'dias' => 12, 'inst' => 'Cuestionario de los temas 1-3.'),
    array('nombre' => 'Trabajo final',        'dias' => 20,  'inst' => 'Trabajo final integrador.'),
);
$asig_ids = array();
foreach ($tareas as $t) {
    $aid = (int) $DB->get_field_sql(
        "SELECT id FROM {assign} WHERE course = :c AND name = :n",
        array('c' => $cid, 'n' => $t['nombre']));
    if (!$aid) {
        $r = tool('crear_tarea', array(
            'curso_id' => $cid, 'nombre' => $t['nombre'],
            'instrucciones' => $t['inst'],
            'fecha_limite_ts' => time() + $t['dias'] * 86400,
            'nota_maxima' => 100));
        $aid = (int) ($r['instanceid'] ?? 0);
        if (!$aid) {
            $aid = (int) $DB->get_field_sql(
                "SELECT id FROM {assign} WHERE course = :c AND name = :n",
                array('c' => $cid, 'n' => $t['nombre']));
        }
    }
    $asig_ids[$t['nombre']] = $aid;
    echo "INFO: tarea '{$t['nombre']}' -> assign $aid\n";
}
$okt = count(array_filter($asig_ids));
ok($okt === count($tareas), "tareas creadas/reutilizadas ($okt/" . count($tareas) . ")");

// --- 5. Entregas (SQL: el WS no expone "entregar como estudiante") -------
// Patron identico al seed de t5/t6: assign_submission + onlinetext.
$informe = $asig_ids['Informe inicial'];
$duedate_informe = (int) $DB->get_field('assign', 'duedate', array('id' => $informe));

// Idempotencia: borrar entregas previas de ESTAS tareas antes de recrearlas.
foreach ($asig_ids as $aid) {
    $subs = $DB->get_records('assign_submission', array('assignment' => $aid));
    foreach ($subs as $s) {
        $DB->delete_records('assignsubmission_onlinetext', array('submission' => $s->id));
    }
    $DB->delete_records('assign_submission', array('assignment' => $aid));
}

/**
 * Crea una entrega 'submitted' con texto.
 * @param int $aid assignment id
 * @param int $uid user id
 * @param int $timecreated timestamp (controla a tiempo vs tardia)
 */
function entregar(int $aid, int $uid, int $timecreated): void {
    global $DB;
    $sub = new stdClass();
    $sub->assignment = $aid;
    $sub->userid = $uid;
    $sub->status = 'submitted';
    $sub->attemptnumber = 0;
    $sub->timecreated = $timecreated;
    $sub->timemodified = $timecreated;
    $sub->latest = 1;
    $sub->id = $DB->insert_record('assign_submission', $sub);
    $txt = new stdClass();
    $txt->assignment = $aid;
    $txt->submission = $sub->id;
    $txt->onlinetext = "Entrega simulada de la tarea $aid (e2e t7).";
    $txt->onlineformat = 1;
    $DB->insert_record('assignsubmission_onlinetext', $txt);
}

foreach ($asig_ids as $nombre => $aid) {
    $duedate = (int) $DB->get_field('assign', 'duedate', array('id' => $aid));
    for ($i = 1; $i <= 15; $i++) {
        entregar($aid, $uids[$i], $duedate - 5 * 86400);            // A tiempo.
    }
    if ($nombre === 'Informe inicial') {
        for ($i = 16; $i <= 18; $i++) {
            entregar($aid, $uids[$i], $duedate + 86400);            // Tardias.
        }
    }
    // sim19 y sim20 no entregan (en ninguna tarea).
}
$nsubs = $DB->count_records('assign_submission', array('assignment' => $informe));
ok($nsubs === 18, "entregas del Informe inicial: 18 (15 a tiempo + 3 tardias, 2 sin entregar; hay $nsubs)");

// --- 6. Correcciones: lote sobre el Informe inicial (via tool) -----------
$notas = array(78, 92, 64, 85, 71, 98, 55, 88, 76, 90, 62, 83, 69, 95, 74);
$calificaciones = array();
foreach ($notas as $i => $nota) {
    $calificaciones[] = array(
        'asignacion_id' => $informe,
        'estudiante_id' => $uids[$i + 1],
        'nota' => $nota,
        'comentario' => 'Correccion simulada: ' . $nota . '/100. Bien, revisar los apartados 2 y 3.',
    );
}
$rlote = tool('calificar_lote', array('calificaciones' => $calificaciones));
ok($rlote !== null, 'calificar_lote aplicado (15 notas)');

// --- 7. Verificacion del flujo -------------------------------------------
$ra = tool('analizar_entregas_pendientes', array(
    'asignacion_id' => $informe, 'fecha_limite_ts' => $duedate_informe));
$sum = $ra['resumen'] ?? array();
echo 'INFO: analizar_entregas_pendientes -> ' . json_encode($sum) . "\n";
ok(($sum['entregados'] ?? 0) === 18, 'analizar: 18 entregados');
ok(($sum['tardias'] ?? 0) === 3, 'analizar: 3 tardias');
ok(($sum['no_entregados'] ?? 0) === 2, 'analizar: 2 sin entregar');
// El lote califico a los 15 a tiempo; los 3 tardios quedaron SIN nota:
// quedan pendientes de correccion (justo lo que el profesor ve en la UI).
ok(($sum['sin_nota'] ?? -1) === 3, 'analizar: 3 sin nota (los tardios, pendientes de correccion)');

$ru = tool('tareas_urgentes', array());
$json = json_encode($ru);
ok($ru !== null && stripos($json, 'Informe inicial') !== false,
    'tareas_urgentes incluye el Informe inicial vencido');

$rpro = tool('otorgar_prorroga', array(
    'asignacion_id' => $informe, 'estudiante_id' => $uids[16],
    'nueva_fecha' => (string) ($duedate_informe + 3 * 86400)));
ok($rpro !== null, 'otorgar_prorroga a un estudiante tardio (sim16)');

$rest = tool('estado_estudiante', array(
    'asignacion_id' => $informe, 'estudiante_id' => $uids[1]));
$json2 = json_encode($rest);
ok($rest !== null && stripos($json2, '78') !== false,
    'estado_estudiante refleja la nota 78 de sim01');

echo "\nCurso: $fullname (id $cid) | url: " . $CFG->wwwroot . "/course/view.php?id=$cid\n";
echo ($fails === 0) ? "TODOS OK\n" : "$fails FALLOS\n";
exit($fails === 0 ? 0 : 1);
