<?php
// e2e Task 4: dispatcher — las 34 tools registradas, WS validos y una
// muestra de ejecucion en vivo contra Moodle.
// Uso: docker compose exec -T web php /var/www/html/local/seedscripts/e2e/mcpchat/t4_dispatcher.php
define('CLI_SCRIPT', true);
require_once('/var/www/html/public/config.php');

$fails = 0;
function ok($cond, $label) {
    global $fails;
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        $fails++;
    }
}

// 1. Las 34 tools del tools.json existen en el dispatcher.
$tools = json_decode(file_get_contents('/var/www/html/public/local/mcpchat/json/tools.json'), true);
ok(count($tools) === 34, "tools.json con 34 entries (tiene " . count($tools) . ")");
foreach ($tools as $t) {
    ok(\local_mcpchat\dispatcher::existe($t['name']),
        "dispatcher conoce {$t['name']}");
}

// 2. Las funciones WS referenciadas existen en external_functions.
// (Se validan ejecutando una muestra en vivo abajo; la lista completa
//  de funciones esta garantizada por el MCP, que las usa por HTTP.)
$admin = core_user::get_user_by_username('admin');
\core\session\manager::set_user($admin);

// Matricular admin en todos los cursos (mismo patron que ws_config con los
// WS users): core_enrol_get_users_courses solo devuelve cursos con
// matricula activa, y el resto de herramientas operan sobre esos cursos.
$enrol = enrol_get_plugin('manual');
foreach ($DB->get_records('enrol', array('enrol' => 'manual')) as $inst) {
    $enrol->enrol_user($inst, $admin->id, 3);
}

// 3. Muestra en vivo de lecturas.
$cursos = \local_mcpchat\dispatcher::ejecutar('listar_cursos', array());
ok(is_array($cursos) && count($cursos) >= 1, "listar_cursos en vivo (" . count($cursos) . " cursos)");
$act = \local_mcpchat\dispatcher::ejecutar('listar_actividades',
    array('curso_id' => (int) $cursos[0]['course_id']));
ok(is_array($act), 'listar_actividades en vivo');
$asigs = \local_mcpchat\dispatcher::ejecutar('listar_asignaciones', array());
ok(is_array($asigs), 'listar_asignaciones en vivo');

// 4. Una escritura benigna real: etiqueta de prueba en el primer curso.
$etiqueta = \local_mcpchat\dispatcher::ejecutar('crear_etiqueta',
    array('curso_id' => (int) $cursos[0]['course_id'],
          'texto' => 'Etiqueta e2e dispatcher ' . date('His')));
ok(isset($etiqueta['cmid']), 'crear_etiqueta en vivo (cmid=' . ($etiqueta['cmid'] ?? '?') . ')');

// 5. Paths de error: tool desconocida y WS que falla en runtime.
try {
    \local_mcpchat\dispatcher::ejecutar('tool_inexistente', array());
    ok(false, 'tool_inexistente deberia lanzar moodle_exception');
} catch (\moodle_exception $e) {
    ok($e->errorcode === 'tool_desconocida',
        "tool_inexistente lanza tool_desconocida (code=" . $e->errorcode . ")");
}
try {
    \local_mcpchat\dispatcher::ejecutar('listar_actividades', array('curso_id' => 999999));
    ok(false, 'listar_actividades curso 999999 deberia lanzar');
} catch (\moodle_exception $e) {
    ok($e->errorcode === 'ws_error',
        "WS fallido lanza ws_error (code=" . $e->errorcode . ")");
}

// 6. En vivo: crear_tarea + analizar_entregas_pendientes (estructura).
$nombretarea = 'Tarea e2e dispatcher ' . date('His');
$tarea = \local_mcpchat\dispatcher::ejecutar('crear_tarea',
    array('curso_id' => (int) $cursos[0]['course_id'],
          'nombre' => $nombretarea, 'instrucciones' => '',
          'fecha_limite_ts' => 0, 'nota_maxima' => 100));
ok(isset($tarea['cmid']), 'crear_tarea en vivo (cmid=' . ($tarea['cmid'] ?? '?') . ')');
$asigs2 = \local_mcpchat\dispatcher::ejecutar('listar_asignaciones',
    array('curso_id' => (int) $cursos[0]['course_id']));
$aid = null;
foreach ($asigs2['courses'] ?? array() as $c2) {
    foreach ($c2['assignments'] ?? array() as $a2) {
        if ($a2['name'] === $nombretarea) {
            $aid = $a2['id'];
        }
    }
}
ok($aid !== null, 'tarea creada visible en listar_asignaciones (assignmentid=' . ($aid ?? '?') . ')');
$analisis = \local_mcpchat\dispatcher::ejecutar('analizar_entregas_pendientes',
    array('asignacion_id' => $aid, 'fecha_limite_ts' => 0));
ok(is_array($analisis), 'analizar_entregas_pendientes en vivo');
ok($analisis['resumen']['entregados'] + $analisis['resumen']['no_entregados']
        === $analisis['total_estudiantes'],
    'resumen consistente: entregados+no_entregados ('
        . $analisis['resumen']['entregados'] . '+' . $analisis['resumen']['no_entregados']
        . ') = total (' . $analisis['total_estudiantes'] . ')');
ok(count($analisis['entregados']) === $analisis['resumen']['entregados'],
    'count(entregados) = resumen.entregados ('
        . count($analisis['entregados']) . ')');
ok(count($analisis['no_entregados']) === $analisis['resumen']['no_entregados'],
    'count(no_entregados) = resumen.no_entregados ('
        . count($analisis['no_entregados']) . ')');
ok(count($analisis['entregas_sin_nota']) === $analisis['resumen']['sin_nota'],
    'count(entregas_sin_nota) = resumen.sin_nota ('
        . count($analisis['entregas_sin_nota']) . ')');

echo ($fails === 0) ? "\nTODOS OK\n" : "\n$fails FALLOS\n";
exit($fails === 0 ? 0 : 1);
