<?php
// e2e Task 5: flujo integral del bot con la API real de DeepSeek.
// Uso: DEEPSEEK_API_KEY=sk-... docker compose exec -T web php \
//      /var/www/html/local/seedscripts/e2e/mcpchat/t5_flow.php
define('CLI_SCRIPT', true);
require_once('/var/www/html/public/config.php');
// Moodle 5.2: user_create_user vive en user/lib.php, que config.php no
// autoloada en CLI (necesario para la seccion 5, estudiante e2e).
require_once($CFG->dirroot . '/user/lib.php');

$key = getenv('DEEPSEEK_API_KEY');
if (!$key) {
    fwrite(STDERR, "FALTA DEEPSEEK_API_KEY\n");
    exit(1);
}
set_config('apikey', $key, 'local_mcpchat');
set_config('maxmsgsday', '100', 'local_mcpchat');
// El system prompt debe ser el DEFAULT del plugin (settings.php): si el
// sandbox tiene una fila vieja en config, se borra para que aplique el
// texto nuevo (las instrucciones de tools-riesgosas son parte del fix).
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

// Conversacion idempotente.
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
// Estado limpio por run: sin tasks del bot en cola (un run cortado deja
// tasks viejas que se disparan durante ESTE run y contaminan los turnos:
// ecos fantasma, pendientes espurios), sin pendientes y sin historial
// previo en la conversacion (el LLM lee los ultimos N mensajes).
$DB->delete_records('task_adhoc', array(
    'classname' => '\\local_mcpchat\\task\\process_message'));
$DB->delete_records('local_mcpchat_pending', array('userid' => $teacher->id));
$DB->delete_records('messages', array('conversationid' => $conv));
// Auditoria: la seccion 4 exige >=1 calificacion ok de ESTE run (la de un
// run anterior no debe dar PASS por accidente).
$DB->delete_records('local_mcpchat_audit', array('userid' => $teacher->id));

function turno(int $teacher, int $bot, int $conv, string $texto): ?string {
    $sent = \core_message\api::send_message_to_conversation($teacher, $conv, $texto, FORMAT_MOODLE);
    passthru('php /var/www/html/admin/cli/cron.php > /dev/null 2>&1');
    $msgs = \core_message\api::get_conversation_messages($bot, $conv, 0, 3, 'timecreated DESC');
    foreach ($msgs['messages'] as $m) {
        // Solo respuestas del bot posteriores al mensaje de ESTE turno
        // (una respuesta vieja de un run anterior no debe dar PASS).
        // OJO: $bot aqui es el id (int) que llega por parametro, no un
        // objeto core_user: acceder a ->id sobre un int da null en PHP 8
        // y la comparacion contra 0 nunca casa (turno() devolvia null).
        if ((int) $m->useridfrom === (int) $bot
                && (int) $m->timecreated >= (int) $sent->timecreated) {
            return (string) $m->text;
        }
    }
    return null;
}

// 1. Consulta conversacional: el bot debe listar cursos.
$r1 = turno($teacher->id, $bot->id, $conv, '¿Qué cursos hay en el sitio?');
ok($r1 !== null && stripos($r1, 'curso') !== false, "bot responde con cursos: " . substr((string) $r1, 0, 120));

// 2. Escritura benigna directa: crear una tarea de prueba.
$r2 = turno($teacher->id, $bot->id, $conv,
    'Crea una tarea llamada "Tarea e2e bot" en el primer curso que veas, sin fecha límite.');
ok($r2 !== null && (stripos($r2, 'cre') !== false || stripos($r2, 'tarea') !== false),
    "bot crea tarea: " . substr((string) $r2, 0, 120));

// Seed de entregas (desviacion justificada, ver reporte): el sandbox no
// tiene entregas en la primera tarea de varios cursos que el modelo puede
// elegir como "primer curso" (en runs anteriores eligio el curso 3 y el
// curso 6); sin entrega el modelo quema sus 5 iteraciones listando o
// responde en texto y nunca llega a pedir la confirmacion. Se matricula un
// estudiante real (student1, el primero con rol student del curso 2) en
// CADA curso con tareas y se le crea una entrega para su primera tarea
// (idempotente: si ya existe para esa (tarea, estudiante), se omite).
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
    $txt->onlinetext = 'Entrega de prueba para el e2e t5.';
    $txt->onlineformat = 1;
    $DB->insert_record('assignsubmission_onlinetext', $txt);
    echo "Seed: estudiante $seed_userid entregado en asignacion $asig (curso $curso)\n";
}

// 3. Escritura riesgosa: debe pedir confirmacion (no ejecutar). La peticion
// fija la tool y el curso (el sandbox tiene una entrega sembrada en la
// primera tarea de ese curso) para que el LLM no tenga ambiguedad de ids:
// invoca calificar_entrega y el sistema pide confirmacion.
$r3 = turno($teacher->id, $bot->id, $conv,
    'Usa la herramienta calificar_entrega para calificar con 90 la primera ' .
    'entrega de la primera tarea del curso Pruebas MCP 2026 - Clon (ID 3).');
ok($r3 !== null && stripos($r3, 'Confirmas') !== false,
    "riesgosa pide confirmacion: " . substr((string) $r3, 0, 120));

// 4. Confirmacion: "sí" ejecuta (sin LLM) y audita.
$r4 = turno($teacher->id, $bot->id, $conv, 'sí');
$audit = $DB->count_records('local_mcpchat_audit', array(
    'userid' => $teacher->id, 'toolname' => 'calificar_entrega', 'status' => 'ok'));
ok($r4 !== null && stripos($r4, 'Ejecutado') !== false && $audit >= 1,
    "confirmacion ejecuta y audita (audit=$audit): " . substr((string) $r4, 0, 120));

// 5. Estudiante sin capability: ignorado (sin respuesta).
$student = core_user::get_user_by_username('e2e_student');
if (!$student) {
    $u = new stdClass();
    $u->username = 'e2e_student';
    $u->auth = 'manual';
    $u->password = 'E2eStudent123!';
    $u->firstname = 'E2E';
    $u->lastname = 'Student';
    $u->email = 'e2e_student@example.local';
    // Moodle 5.2: user_create_user inserta SIN mnethostid (default de la BD
    // 0); get_user_by_username filtra por mnethostid local (1) y un re-run
    // tras un corte chocaria con el indice unico (mnethostid, username).
    // Ademas devuelve el id (int), no el objeto: se recarga con core_user.
    $u->mnethostid = (int) $CFG->mnet_localhost_id;
    $student = core_user::get_user(user_create_user($u, false));
}
// messagingallusers desactivado (Moodle 5.2): el estudiante (sin cursos ni
// contactos) no puede escribir al bot; mismo patron de contactos que t2.
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
\core_message\api::send_message_to_conversation($student->id, $conv2, 'hola', FORMAT_MOODLE);
passthru('php /var/www/html/admin/cli/cron.php > /dev/null 2>&1');
$msgs2 = \core_message\api::get_conversation_messages($bot->id, $conv2, 0, 3, 'timecreated DESC');
$huborespuesta = true;
foreach ($msgs2['messages'] as $m) {
    if ((int) $m->useridfrom === (int) $bot->id) {
        $huborespuesta = false;
    }
}
ok($huborespuesta, 'estudiante sin capability es ignorado');

// Limpieza: usuario de prueba.
delete_user($student);

echo ($fails === 0) ? "\nTODOS OK\n" : "\n$fails FALLOS\n";
exit($fails === 0 ? 0 : 1);
