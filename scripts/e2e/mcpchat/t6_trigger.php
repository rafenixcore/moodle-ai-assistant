<?php
// e2e Task 6: disparo inmediato (trigger_turno, sin cron) + respuestas HTML.
// Misma prelude/cleanup/seed que t5, pero cada turno se dispara con
// \local_mcpchat\external\trigger_turno::execute() directo (el mismo path
// que el ajax del navegador; el smoke manual cubre ajax+sesskey completo).
// Uso: DEEPSEEK_API_KEY=sk-... docker compose exec -T web php \
//      /var/www/html/local/seedscripts/e2e/mcpchat/t6_trigger.php
define('CLI_SCRIPT', true);
require_once('/var/www/html/public/config.php');
require_once($CFG->dirroot . '/user/lib.php');

$key = getenv('DEEPSEEK_API_KEY');
if (!$key) {
    fwrite(STDERR, "FALTA DEEPSEEK_API_KEY\n");
    exit(1);
}
set_config('apikey', $key, 'local_mcpchat');
set_config('maxmsgsday', '100', 'local_mcpchat');
// El system prompt debe ser el DEFAULT del plugin (settings.php).
unset_config('systemprompt', 'local_mcpchat');

$fails = 0;
function ok($cond, $label) {
    global $fails;
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        $fails++;
    }
}

$teacher = core_user::get_user_by_username('admin');
$bot = core_user::get_user_by_username('asistente_ia');
set_user_preferences(array('message_blocknoncontacts' => 0), $teacher->id);

// Conversacion idempotente admin<->bot.
$sql = "SELECT mc.id FROM {message_conversations} mc
        JOIN {message_conversation_members} m1 ON m1.conversationid = mc.id AND m1.userid = :u1
        JOIN {message_conversation_members} m2 ON m2.conversationid = mc.id AND m2.userid = :u2
        WHERE mc.type = :t LIMIT 1";
$conv = $DB->get_field_sql($sql, array(
    'u1' => $teacher->id, 'u2' => $bot->id,
    't' => \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL));
if (!$conv) {
    $conv = \core_message\api::create_conversation(
        \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
        array($teacher->id, $bot->id))->id;
}
// Estado limpio por run (misma higiene que t5: un run cortado deja tasks
// viejas y mensajes que contaminan el siguiente).
$DB->delete_records('task_adhoc', array(
    'classname' => '\\local_mcpchat\\task\\process_message'));
$DB->delete_records('local_mcpchat_pending', array('userid' => $teacher->id));
$DB->delete_records('messages', array('conversationid' => $conv));
$DB->delete_records('local_mcpchat_audit', array('userid' => $teacher->id));

/**
 * Turno disparado al instante (sin cron): envia el mensaje (el observer
 * encola la adhoc task) y la procesa con trigger_turno::execute() como
 * usuario remitente — el path exacto del ajax del navegador.
 *
 * @return array ['procesado' => bool, 'motivo' => string,
 *                'respuesta' => ?stdClass fila de {messages} del bot]
 */
function turno_ws(int $teacher, int $bot, int $conv, string $texto): array {
    global $DB;
    $sent = \core_message\api::send_message_to_conversation(
        $teacher, $conv, $texto, FORMAT_MOODLE);
    \core\session\manager::set_user(core_user::get_user($teacher));
    $res = \local_mcpchat\external\trigger_turno::execute(
        $conv, (int) $sent->id);
    // Respuesta del bot posterior al mensaje de ESTE turno (FORMAT_HTML:
    // el HTML vive en fullmessagehtml).
    $respuesta = $DB->get_record_sql(
        "SELECT id, fullmessagehtml, timecreated FROM {messages}
          WHERE conversationid = :c AND useridfrom = :b AND timecreated >= :t
          ORDER BY id DESC LIMIT 1",
        array('c' => $conv, 'b' => $bot, 't' => (int) $sent->timecreated));
    return array('procesado' => (bool) $res['procesado'],
                 'motivo' => (string) ($res['motivo'] ?? ''),
                 'respuesta' => $respuesta ?: null);
}

// 1. El WS esta registrado y permitido desde ajax (requiere version bump +
// upgrade previos). En Moodle 5.2 la columna 'ajax' de external_functions ya
// NO existe: external_function_info() lee el flag de db/services.php (y el
// registro de la BD se sincronizo con el bump de version).
$info = \core_external\external_api::external_function_info(
    'local_mcpchat_trigger_turno');
ok($info && $info->allowed_from_ajax,
    'WS local_mcpchat_trigger_turno registrado y permitido desde ajax');

// 1b. Conversor de markdown: la tabla |a|b| -> <table> real, y los ids
// internos se omiten (columna ID y "(ID N)" en prosa) aunque el modelo los
// emita — queja de UX de Task 7, cubierta de forma determinista.
$rc = new ReflectionClass('local_mcpchat\bot');
$mconv = $rc->getMethod('markdown_a_html');
$mconv->setAccessible(true);
$botinst = $rc->newInstanceWithoutConstructor();
$conv1 = $mconv->invoke($botinst,
    "| ID | Curso |\n|----|-------|\n| 2 | Pruebas |");
ok(strpos($conv1, '<th>ID</th>') === false
        && strpos($conv1, '<td>2</td>') === false
        && strpos($conv1, 'Pruebas') !== false
        && strpos($conv1, '<table>') !== false,
    'conversor omite la columna ID de las tablas');
$conv2 = $mconv->invoke($botinst, 'Tarea creada en el curso Pruebas (ID 7).');
ok(strpos($conv2, '(ID 7)') === false && stripos($conv2, 'Pruebas') !== false,
    'conversor elimina ids en prosa');

// 2. Consulta: turno inmediato responde en HTML (procesado=true, sin cron).
$r1 = turno_ws($teacher->id, $bot->id, $conv, '¿Qué cursos hay en el sitio?');
$t1 = (string) ($r1['respuesta']->fullmessagehtml ?? '');
ok($r1['procesado'] && stripos($t1, 'curso') !== false && stripos($t1, '<p') !== false,
    'consulta responde HTML al instante: ' . substr($t1, 0, 100));

// 3. La adhoc task quedo borrada (no queda turno pendiente para el cron).
$ntasks = $DB->count_records('task_adhoc', array(
    'classname' => '\\local_mcpchat\\task\\process_message'));
ok($ntasks === 0, "task_adhoc sin tareas del bot tras el turno (tiene $ntasks)");

// 4. Cron posterior NO duplica la respuesta.
$nbot1 = $DB->count_records('messages',
    array('conversationid' => $conv, 'useridfrom' => $bot->id));
passthru('php /var/www/html/admin/cli/cron.php > /dev/null 2>&1');
$nbot2 = $DB->count_records('messages',
    array('conversationid' => $conv, 'useridfrom' => $bot->id));
ok($nbot2 === $nbot1 && $nbot1 >= 1,
    "cron posterior no duplica la respuesta ($nbot1 -> $nbot2)");

// 5. Re-trigger del mismo messageid: sin tarea encalada -> sin_tarea.
// (El unico mensaje del usuario en la conversacion a esta altura es el de
//  la consulta del turno 2, ya procesado y borrado.)
$msguser = $DB->get_record_sql(
    "SELECT id FROM {messages}
      WHERE conversationid = :c AND useridfrom = :u
      ORDER BY id DESC LIMIT 1",
    array('c' => $conv, 'u' => $teacher->id));
$res5 = \local_mcpchat\external\trigger_turno::execute($conv, (int) $msguser->id);
ok(!$res5['procesado'] && $res5['motivo'] === 'sin_tarea',
    're-trigger sin tarea -> sin_tarea (' . ($res5['motivo'] ?? '?') . ')');

// 6. Trigger con el messageid de un mensaje DEL BOT: rechazado.
$res6 = \local_mcpchat\external\trigger_turno::execute(
    $conv, (int) $r1['respuesta']->id);
ok(!$res6['procesado'] && $res6['motivo'] === 'mensaje_del_bot',
    'messageid del bot rechazado (' . ($res6['motivo'] ?? '?') . ')');

// Seed de entregas (mismo bloque que t5): el sandbox no tiene entregas en
// la primera tarea de todos los cursos; se matricula a un estudiante real
// y se le crea una entrega en la primera tarea de CADA curso con tareas.
$seed_userid = (int) $DB->get_field_sql(
    "SELECT ue.userid FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid AND e.enrol = 'manual'
       JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.roleid = 5
        AND ra.contextid = (SELECT id FROM {context}
                             WHERE contextlevel = :cl AND instanceid = e.courseid)
      WHERE e.courseid = 2 AND ue.status = 0 ORDER BY ue.userid LIMIT 1",
    array('cl' => CONTEXT_COURSE));
$cursos_seed = $DB->get_records_sql(
    "SELECT DISTINCT a.course FROM {assign} a ORDER BY a.course");
foreach ($cursos_seed as $row) {
    $curso = (int) $row->course;
    $asig = (int) $DB->get_field_sql(
        "SELECT id FROM {assign} WHERE course = :c ORDER BY id LIMIT 1",
        array('c' => $curso));
    if (!$asig || !$seed_userid
            || $DB->record_exists('assign_submission',
                array('assignment' => $asig, 'userid' => $seed_userid))) {
        continue;
    }
    $instancia = $DB->get_record('enrol', array('courseid' => $curso, 'enrol' => 'manual'), '*', IGNORE_MULTIPLE);
    if (!$instancia) {
        continue;
    }
    if (!$DB->record_exists('user_enrolments', array('enrolid' => $instancia->id, 'userid' => $seed_userid))) {
        enrol_get_plugin('manual')->enrol_user($instancia, $seed_userid, 5); // student.
    }
    $sub = new stdClass();
    $sub->assignment = $asig;
    $sub->userid = $seed_userid;
    $sub->status = 'submitted';
    $sub->attemptnumber = 0;
    $sub->timecreated = time();
    $sub->timemodified = time();
    $sub->latest = 1;
    $sub->id = $DB->insert_record('assign_submission', $sub);
    $txt = new stdClass();
    $txt->assignment = $asig;
    $txt->submission = $sub->id;
    $txt->onlinetext = 'Entrega de prueba para el e2e t6.';
    $txt->onlineformat = 1;
    $DB->insert_record('assignsubmission_onlinetext', $txt);
    echo "Seed: estudiante $seed_userid entregado en asignacion $asig (curso $curso)\n";
}

// 7. Escritura riesgosa: confirmacion HTML humanizada (nunca JSON crudo).
$r3 = turno_ws($teacher->id, $bot->id, $conv,
    'Usa la herramienta calificar_entrega para calificar con 90 la primera ' .
    'entrega de la primera tarea del curso Pruebas MCP 2026 - Clon (ID 3).');
$t3 = (string) ($r3['respuesta']->fullmessagehtml ?? '');
ok($r3['procesado'] && stripos($t3, 'Confirmas') !== false
        && stripos($t3, '<ul') !== false && strpos($t3, '{') === false,
    'riesgosa pide confirmacion en HTML sin JSON: ' . substr($t3, 0, 100));

// 8. "sí" ejecuta (sin LLM) y audita.
$r4 = turno_ws($teacher->id, $bot->id, $conv, 'sí');
$t4 = (string) ($r4['respuesta']->fullmessagehtml ?? '');
$audit = $DB->count_records('local_mcpchat_audit', array(
    'userid' => $teacher->id, 'toolname' => 'calificar_entrega', 'status' => 'ok'));
ok($r4['procesado'] && stripos($t4, 'Ejecutado') !== false
        && strpos($t4, '{') === false && $audit >= 1,
    "confirmacion ejecuta y audita en HTML sin JSON (audit=$audit): " . substr($t4, 0, 100));

// 9. Estudiante sin capability: trigger_turno lanza (require_capability).
$student = core_user::get_user_by_username('e2e_student');
if (!$student) {
    $u = new stdClass();
    $u->username = 'e2e_student';
    $u->auth = 'manual';
    $u->password = 'E2eStudent123!';
    $u->firstname = 'E2E';
    $u->lastname = 'Student';
    $u->email = 'e2e_student@example.local';
    $u->mnethostid = (int) $CFG->mnet_localhost_id;
    $student = core_user::get_user(user_create_user($u, false));
}
if (!\core_message\api::is_contact($bot->id, $student->id)) {
    \core_message\api::create_contact_request($bot->id, $student->id);
    \core_message\api::confirm_contact_request($bot->id, $student->id);
}
$sql2 = "SELECT mc.id FROM {message_conversations} mc
         JOIN {message_conversation_members} m1 ON m1.conversationid = mc.id AND m1.userid = :u1
         JOIN {message_conversation_members} m2 ON m2.conversationid = mc.id AND m2.userid = :u2
         WHERE mc.type = :t LIMIT 1";
$conv2 = $DB->get_field_sql($sql2, array(
    'u1' => $student->id, 'u2' => $bot->id,
    't' => \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL));
if (!$conv2) {
    $conv2 = \core_message\api::create_conversation(
        \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
        array($student->id, $bot->id))->id;
}
$sent2 = \core_message\api::send_message_to_conversation(
    $student->id, $conv2, 'hola', FORMAT_MOODLE);
\core\session\manager::set_user($student);
try {
    \local_mcpchat\external\trigger_turno::execute($conv2, (int) $sent2->id);
    ok(false, 'estudiante sin capability deberia lanzar');
} catch (\required_capability_exception $e) {
    ok(true, 'estudiante sin capability: require_capability lanza');
} catch (\Throwable $e) {
    ok(false, 'excepcion inesperada: ' . get_class($e) . ': ' . $e->getMessage());
}

// Limpieza: usuario de prueba.
delete_user($student);

echo ($fails === 0) ? "\nTODOS OK\n" : "\n$fails FALLOS\n";
exit($fails === 0 ? 0 : 1);
