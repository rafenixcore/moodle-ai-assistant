<?php
// This file is part of the local_mcpchat plugin for Moodle.
namespace local_mcpchat;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Ejecuta las tools del bot sobre las funciones WS de Moodle.
 * Se llama con set_user(remitente) ya hecho (lo hace task/process_message):
 * cada funcion WS aplica sus propios chequeos de capacidad sobre $USER.
 *
 * SIMPLES: una sola llamada WS con un constructor de argumentos.
 * COMPUESTAS: tools con logica propia (varias llamadas o post-proceso:
 * listar_cursos, buscar_usuario, analizar_entregas_pendientes, clonar_curso,
 * listar_entregas).
 */
class dispatcher {

    /** @var array tool => [funcion_ws, builder(args): array] */
    private static $SIMPLES;

    /** @var array tool => callable(args): array (usa self::ws) */
    private static $COMPUESTAS;

    /**
     * Pobla las tablas una vez (lazy). PHP no permite closures en const ni
     * en inicializadores de propiedades (expresiones constantes), asi que
     * las tablas se construyen en runtime.
     */
    private static function tablas(): void {
        if (self::$SIMPLES !== null) {
            return;
        }
        self::$SIMPLES = array(
            // --- lectura ---
            'listar_actividades' => array('core_course_get_contents',
                function (array $a) { return array('courseid' => $a['curso_id'] ?? null); }),
            'listar_asignaciones' => array('mod_assign_get_assignments',
                function (array $a) {
                    return empty($a['curso_id']) ? array()
                        : array('courseids' => array($a['curso_id']));
                }),
            'tareas_urgentes' => array('mod_assign_get_assignments',
                function (array $a) { return array(); }),
            'estado_estudiante' => array('mod_assign_get_submission_status',
                function (array $a) {
                    return array('assignid' => $a['asignacion_id'] ?? null,
                                 'userid' => $a['estudiante_id'] ?? null);
                }),
            'resumir_entrega' => array('local_mcpbridge_get_submission_text',
                function (array $a) { return array('submissionid' => $a['submission_id'] ?? null); }),
            'estado_clonacion' => array('core_backup_get_copy_progress',
                function (array $a) {
                    return array('copies' => array(array(
                        'backupid' => $a['backupid'] ?? null,
                        'restoreid' => $a['restoreid'] ?? null,
                        'operation' => $a['operation'] ?? 'copy')));
                }),
            'listar_foros' => array('mod_forum_get_forums_by_courses',
                function (array $a) { return array('courseids' => array($a['curso_id'] ?? null)); }),
            'listar_discusiones' => array('mod_forum_get_forum_discussions',
                function (array $a) { return array('forumid' => $a['foro_id'] ?? null); }),
            // --- escritura ---
            'crear_curso' => array('core_course_create_courses',
                function (array $a) {
                    return array('courses' => array(array(
                        'fullname' => $a['nombre'] ?? null, 'shortname' => $a['shortname'] ?? null,
                        'categoryid' => $a['categoria_id'] ?? 1,
                        'summary' => $a['descripcion'] ?? '', 'summaryformat' => 1)));
                }),
            'crear_tarea' => array('local_mcpbridge_create_activity',
                function (array $a) {
                    return array('courseid' => $a['curso_id'] ?? null, 'type' => 'assign',
                        'name' => $a['nombre'] ?? null, 'intro' => $a['instrucciones'] ?? '',
                        'duedate' => $a['fecha_limite_ts'] ?? 0,
                        'grade' => $a['nota_maxima'] ?? 100);
                }),
            'crear_pagina' => array('local_mcpbridge_create_activity',
                function (array $a) {
                    return array('courseid' => $a['curso_id'] ?? null, 'type' => 'page',
                        'name' => $a['nombre'] ?? null, 'intro' => $a['instrucciones'] ?? '');
                }),
            'crear_recurso' => array('local_mcpbridge_create_activity',
                function (array $a) {
                    return array('courseid' => $a['curso_id'] ?? null, 'type' => 'resource',
                        'name' => $a['nombre'] ?? null, 'intro' => $a['instrucciones'] ?? '',
                        'content' => $a['contenido'] ?? '');
                }),
            'crear_enlace' => array('local_mcpbridge_create_activity',
                function (array $a) {
                    return array('courseid' => $a['curso_id'] ?? null, 'type' => 'url',
                        'name' => $a['nombre'] ?? null, 'intro' => '',
                        'content' => $a['url'] ?? null);
                }),
            'crear_etiqueta' => array('local_mcpbridge_create_activity',
                function (array $a) {
                    return array('courseid' => $a['curso_id'] ?? null, 'type' => 'label',
                        'name' => $a['texto'] ?? null, 'intro' => '');
                }),
            'otorgar_prorroga' => array('mod_assign_save_user_extensions',
                function (array $a) {
                    if (!is_numeric($a['nueva_fecha'] ?? null)) {
                        throw new \invalid_parameter_exception(
                            'nueva_fecha debe ser un timestamp Unix');
                    }
                    return array('assignmentid' => $a['asignacion_id'] ?? null,
                        'userids' => array($a['estudiante_id'] ?? null),
                        'dates' => array((int) $a['nueva_fecha']));
                }),
            'bloquear_entregas' => array('mod_assign_lock_submissions',
                function (array $a) {
                    return array('assignmentid' => $a['asignacion_id'] ?? null,
                        'userids' => array($a['estudiante_id'] ?? null));
                }),
            'desbloquear_entregas' => array('mod_assign_unlock_submissions',
                function (array $a) {
                    return array('assignmentid' => $a['asignacion_id'] ?? null,
                        'userids' => array($a['estudiante_id'] ?? null));
                }),
            'revertir_a_borrador' => array('mod_assign_revert_submissions_to_draft',
                function (array $a) {
                    return array('assignmentid' => $a['asignacion_id'] ?? null,
                        'userids' => array($a['estudiante_id'] ?? null));
                }),
            'crear_usuario' => array('core_user_create_users',
                function (array $a) {
                    return array('users' => array(array(
                        'username' => $a['username'] ?? null, 'password' => $a['password'] ?? null,
                        'firstname' => $a['firstname'] ?? null, 'lastname' => $a['lastname'] ?? null,
                        'email' => $a['email'] ?? null)));
                }),
            'matricular_usuario' => array('enrol_manual_enrol_users',
                function (array $a) {
                    $roles = array('student' => 5, 'teacher' => 3,
                                   'noneditingteacher' => 4);
                    return array('enrolments' => array(array(
                        'roleid' => $roles[$a['rol'] ?? 'student'] ?? 5,
                        'userid' => $a['usuario_id'] ?? null,
                        'courseid' => $a['curso_id'] ?? null)));
                }),
            'desmatricular_usuario' => array('enrol_manual_unenrol_users',
                function (array $a) {
                    return array('enrolments' => array(array(
                        'userid' => $a['usuario_id'] ?? null,
                        'courseid' => $a['curso_id'] ?? null)));
                }),
            'crear_grupo' => array('core_group_create_groups',
                function (array $a) {
                    return array('groups' => array(array(
                        'courseid' => $a['curso_id'] ?? null, 'name' => $a['nombre'] ?? null,
                        'description' => '')));
                }),
            'agregar_a_grupo' => array('core_group_add_group_members',
                function (array $a) {
                    return array('members' => array(array(
                        'groupid' => $a['grupo_id'] ?? null, 'userid' => $a['usuario_id'] ?? null)));
                }),
            'publicar_anuncio' => array('mod_forum_add_discussion',
                function (array $a) {
                    return array('forumid' => $a['foro_id'] ?? null,
                        'subject' => $a['asunto'] ?? null, 'message' => $a['mensaje'] ?? null);
                }),
            'responder_discusion' => array('mod_forum_add_discussion_post',
                function (array $a) {
                    return array('postid' => $a['post_id'] ?? null,
                        'subject' => $a['asunto'] ?? null, 'message' => $a['mensaje'] ?? null);
                }),
            'enviar_mensaje' => array('core_message_send_messages_to_conversation',
                function (array $a) {
                    return array('conversationid' => $a['conversacion_id'] ?? null,
                        'messages' => array(array('text' => $a['texto'] ?? null,
                                                  'textformat' => 0)));
                }),
            'crear_evento' => array('core_calendar_create_calendar_events',
                function (array $a) {
                    $ev = array('name' => $a['nombre'] ?? null,
                        'eventtype' => empty($a['curso_id']) ? 'site' : 'course',
                        'timestart' => $a['fecha_ini_ts'] ?? null);
                    if (!empty($a['curso_id'])) {
                        $ev['courseid'] = $a['curso_id'];
                    }
                    return array('events' => array($ev));
                }),
            'calificar_entrega' => array('mod_assign_save_grade',
                function (array $a) {
                    $p = array('assignmentid' => $a['asignacion_id'] ?? null,
                        'userid' => $a['estudiante_id'] ?? null,
                        'grade' => $a['nota'] ?? null,
                        'attemptnumber' => -1, 'addattempt' => 0,
                        'workflowstate' => 'released', 'applytoall' => false,
                        'advancedgradingdata' => array(),
                        // plugindata SIEMPRE con el editor de comentarios
                        // (texto vacio si no hay): sin el editor,
                        // file_postupdate_standard_editor() deja
                        // assignfeedbackcomments y su format a null en
                        // assign_feedback_comments::save() y el UPDATE/INSERT
                        // revienta con "Column 'commentformat' cannot be
                        // null" (modo estricto de MySQL). Bug real visto en
                        // el e2e t5 seccion 4 al calificar sin comentario.
                        'plugindata' => array('assignfeedbackcomments_editor' => array(
                            'text' => $a['comentario'] ?? '', 'format' => 1)));
                    return $p;
                }),
            'calificar_lote' => array('mod_assign_save_grades',
                function (array $a) {
                    if (empty($a['calificaciones'])) {
                        throw new \invalid_parameter_exception(
                            'calificaciones invalidas: lote vacio');
                    }
                    $grades = array();
                    foreach ($a['calificaciones'] as $i => $c) {
                        if (!isset($c['asignacion_id'], $c['estudiante_id'], $c['nota'])) {
                            throw new \invalid_parameter_exception(
                                'calificaciones invalidas: item ' . $i
                                    . ' sin asignacion_id/estudiante_id/nota');
                        }
                        $grades[] = array('userid' => $c['estudiante_id'],
                            'grade' => (string) $c['nota'], 'attemptnumber' => -1,
                            'addattempt' => 0, 'workflowstate' => 'released',
                            'advancedgradingdata' => array(),
                            'plugindata' => array('assignfeedbackcomments_editor' => array(
                                'text' => $c['comentario'] ?? '', 'format' => 1)));
                    }
                    return array('assignmentid' => $a['calificaciones'][0]['asignacion_id'],
                        'applytoall' => false, 'grades' => $grades);
                }),
        );
        self::$COMPUESTAS = array(
            'listar_entregas' => function (array $a) {
                $data = self::ws('mod_assign_get_submissions', array(
                    'assignmentids' => array($a['asignacion_id'] ?? null)));
                // El schema de la tool permite filtrar por estudiante
                // (mod_assign_get_submissions no tiene parametro por
                // usuario; el MCP filtra client-side, aqui se replica).
                if (!empty($a['estudiante_id'])) {
                    $uid = (int) $a['estudiante_id'];
                    $subs = $data['assignments'][0]['submissions'] ?? array();
                    $data['assignments'][0]['submissions'] = array_values(
                        array_filter($subs, function (array $s) use ($uid): bool {
                            return (int) $s['userid'] === $uid;
                        }));
                }
                return $data;
            },
            'listar_cursos' => function (array $a) {
                global $USER;
                $cursos = self::ws('core_enrol_get_users_courses',
                    array('userid' => (int) $USER->id));
                return array_map(function (array $c): array {
                    return array('course_id' => $c['id'],
                        'shortname' => $c['shortname'] ?? '',
                        'fullname' => $c['fullname'] ?? '');
                }, $cursos);
            },
            'buscar_usuario' => function (array $a) {
                // Sin username la rama email/firstname armaria '%%' y
                // core_user_get_users devolveria todos los usuarios
                // reportados como coincidencia email.
                if (empty(trim((string) ($a['username'] ?? '')))) {
                    throw new \invalid_parameter_exception('username requerido');
                }
                $criterios = array('username', 'email', 'firstname');
                foreach ($criterios as $key) {
                    $valor = ($key === 'username') ? ($a['username'] ?? null)
                        : '%' . $a['username'] . '%';
                    $data = self::ws('core_user_get_users', array(
                        'criteria' => array(array('key' => $key, 'value' => $valor))));
                    if (!empty($data['users'])) {
                        return array('coincidencia' => $key, 'usuarios' => array_map(
                            function (array $u): array {
                                return array('user_id' => $u['id'],
                                    'fullname' => $u['fullname'] ?? '',
                                    'email' => $u['email'] ?? '');
                            }, $data['users']));
                    }
                }
                return array('coincidencia' => null, 'usuarios' => array());
            },
            'analizar_entregas_pendientes' => function (array $a) {
                $subs = self::ws('mod_assign_get_submissions', array(
                    'assignmentids' => array($a['asignacion_id'] ?? null),
                    'status' => 'submitted'));
                $grades = self::ws('mod_assign_get_grades', array(
                    'assignmentids' => array($a['asignacion_id'] ?? null)));
                $participantes = self::ws('mod_assign_list_participants', array(
                    'assignid' => $a['asignacion_id'] ?? null, 'groupid' => 0, 'filter' => '',
                    'skip' => 0, 'limit' => 0, 'onlyids' => 0, 'includeenrolments' => 1));

                $subsarr = $subs['assignments'][0]['submissions'] ?? array();
                $gradesarr = $grades['assignments'][0]['grades'] ?? array();
                $subs_por_user = array();
                foreach ($subsarr as $s) {
                    $subs_por_user[$s['userid']] = $s;
                }
                $nota_por_user = array();
                foreach ($gradesarr as $g) {
                    // mod_assign_get_grades crea el record de nota al
                    // entregar con grade NULL: entregado-sin-nota debe
                    // quedar como null (no 0.0) para entrar en sin_nota.
                    $nota_por_user[$g['userid']] =
                        $g['grade'] === null ? null : (float) $g['grade'];
                }
                $fecha = $a['fecha_limite_ts'] ?? 0;

                $entregados = array();
                $no_entregados = array();
                $sin_nota = array();
                $resumen = array('entregados' => 0, 'no_entregados' => 0,
                                 'sin_nota' => 0, 'tardias' => 0);
                foreach ($participantes as $p) {
                    $uid = $p['id'];
                    if (!empty($p['submitted'])) {
                        $sub = $subs_por_user[$uid] ?? array();
                        $nota = $nota_por_user[$uid] ?? null;
                        $tarde = $fecha && !empty($sub['timemodified'])
                            && $sub['timemodified'] > $fecha;
                        if ($tarde) {
                            $resumen['tardias']++;
                        }
                        $entregados[] = array('user_id' => $uid,
                            'nombre' => $p['fullname'] ?? '',
                            'submission_id' => $sub['id'] ?? null,
                            'nota' => $nota, 'tardia' => (bool) $tarde);
                        if ($nota === null) {
                            $sin_nota[] = array('user_id' => $uid,
                                'nombre' => $p['fullname'] ?? '',
                                'submission_id' => $sub['id'] ?? null,
                                'tardia' => (bool) $tarde);
                        }
                        $resumen['entregados']++;
                    } else {
                        $no_entregados[] = array('user_id' => $uid,
                            'nombre' => $p['fullname'] ?? '',
                            'estado' => $p['submissionstatus'] ?? 'ninguno');
                        $resumen['no_entregados']++;
                    }
                }
                $resumen['sin_nota'] = count($sin_nota);
                return array('asignacion_id' => $a['asignacion_id'] ?? null,
                    'total_estudiantes' => count($participantes),
                    'fecha_limite_ts' => $fecha,
                    'entregados' => $entregados,
                    'no_entregados' => $no_entregados,
                    'entregas_sin_nota' => $sin_nota,
                    'resumen' => $resumen);
            },
            'clonar_curso' => function (array $a) {
                global $USER;
                $cursos = self::ws('core_enrol_get_users_courses',
                    array('userid' => (int) $USER->id));
                $curso = null;
                foreach ($cursos as $c) {
                    if ((int) $c['id'] === (int) ($a['curso_origen_id'] ?? null)) {
                        $curso = $c;
                        break;
                    }
                }
                if (!$curso) {
                    return array('ok' => false,
                        'error' => 'El curso ' . ($a['curso_origen_id'] ?? '?')
                            . ' no existe (o no es del usuario).');
                }
                $data = self::ws('local_mcpbridge_copy_course', array(
                    'courseid' => (int) $a['curso_origen_id'],
                    'fullname' => $a['nombre_nuevo'] ?? null,
                    'shortname' => $a['shortname_nuevo'] ?? null,
                    'category' => $a['categoria_id'] ?? 1,
                    'visible' => 1,
                    'startdate' => (int) ($curso['startdate'] ?? 0)));
                return array('ok' => true,
                    'copyids' => array('backupid' => $data['backupid'],
                        'restoreid' => $data['restoreid']),
                    'courseid_nuevo' => $data['courseid'],
                    'nota' => 'La copia corre en segundo plano (cron); consulta estado_clonacion.');
            },
        );
    }

    /**
     * Ejecuta una tool. Devuelve la respuesta WS normalizada a array.
     * @throws \moodle_exception si la tool no existe o el WS falla.
     */
    public static function ejecutar(string $toolname, array $args): array {
        self::tablas();
        if (isset(self::$COMPUESTAS[$toolname])) {
            $fn = self::$COMPUESTAS[$toolname];
            return $fn($args);
        }
        if (!isset(self::$SIMPLES[$toolname])) {
            throw new \moodle_exception('tool_desconocida', 'local_mcpchat',
                '', $toolname);
        }
        list($ws, $builder) = self::$SIMPLES[$toolname];
        return self::ws($ws, $builder($args));
    }

    public static function existe(string $toolname): bool {
        self::tablas();
        return isset(self::$SIMPLES[$toolname]) || isset(self::$COMPUESTAS[$toolname]);
    }

    public static function es_riesgosa(string $toolname): bool {
        $lista = explode(',', (string) get_config('local_mcpchat', 'riesgosas'));
        return in_array($toolname, array_map('trim', $lista), true);
    }

    /**
     * Llama una funcion WS en proceso (mismo camino que REST/AJAX).
     * Nota: en Moodle 5.2, call_external_function() bloquea las llamadas
     * en CLI/cron (setup.php define NO_MOODLE_COOKIES=true para todo CLI
     * script y el gate lanza servicerequireslogin para funciones con
     * loginrequired), asi que se reproduce su nucleo en proceso
     * (validate_parameters -> execute -> clean_returnvalue) sin los
     * portones de transporte HTTP. Los chequeos de capacidad viven en el
     * execute() de cada funcion y se aplican igual con set_user() hecho.
     * Errores: invalid_parameter_exception, coding_exception y \Error
     * suben con su tipo (no se enmascaran); el resto de fallos de las WS
     * (dml, acceso, etc.) se envuelven en ws_error con el nombre de la
     * funcion.
     */
    private static function ws(string $function, array $params): array {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        try {
            $info = \external_api::external_function_info($function);
            $params = call_user_func(array($info->classname, 'validate_parameters'),
                $info->parameters_desc, $params);
            $result = call_user_func_array(array($info->classname, $info->methodname),
                array_values($params));
            if ($info->returns_desc !== null) {
                $result = call_user_func(array($info->classname, 'clean_returnvalue'),
                    $info->returns_desc, $result);
            } else if ($result === null) {
                // WS de escritura que no devuelven nada (mod_assign_save_grade,
                // enrol_manual_enrol_users, core_message_send_messages_to_conversation,
                // ...): su execute() se llamo con exito pero el wrapper debe
                // devolver array (bug real visto en el e2e t5 seccion 4:
                // TypeError "Return value must be of type array, null returned"
                // justo despues de escribir la nota).
                $result = array('ok' => true);
            }
            // Normalizar stdClass -> array (JSON_INVALID_UTF8_SUBSTITUTE:
            // un PARAM_RAW con UTF-8 invalido no debe romper el encode).
            return json_decode(json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE), true);
        } catch (\invalid_parameter_exception $e) {
            // Errores de validacion: suben con su tipo y mensaje limpio.
            throw $e;
        } catch (\coding_exception $e) {
            // Errores de programacion: visibles en los logs como tales.
            throw $e;
        } catch (\Error $e) {
            // TypeError y similares: no enmascarar.
            throw $e;
        } catch (\moodle_exception $e) {
            // Fallos de runtime de las WS: se envuelven para anadir el
            // nombre de la funcion al mensaje del bot.
            throw new \moodle_exception('ws_error', 'local_mcpchat', '',
                $function . ': ' . $e->getMessage());
        } catch (\Throwable $ex) {
            throw new \moodle_exception('ws_error', 'local_mcpchat', '',
                $function . ': ' . $ex->getMessage());
        }
    }
}
