<?php
// Este archivo se monta en /var/www/html/local/seedscripts/cli/ws_config.php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/webservice/lib.php');
require_once($CFG->dirroot . '/lib/externallib.php'); // external_generate_token
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');

\core\session\manager::set_user(get_admin());

// --- 1. Servicio "asignaciones_mcp" con las funciones necesarias ---
$service = $DB->get_record('external_services', array('shortname' => 'asignaciones_mcp'));
if (!$service) {
    $service = new stdClass();
    $service->name = 'Asignaciones MCP';
    $service->shortname = 'asignaciones_mcp';
    $service->enabled = 1;
    $service->requiredcapability = '';
    $service->restrictedusers = 0;
    $service->downloadfiles = 1;
    $service->uploadfiles = 1;
    $service->timecreated = time();
    $service->id = $DB->insert_record('external_services', $service);
    echo "Servicio creado: id={$service->id}\n";
} else {
    echo "Servicio existente: id={$service->id}\n";
}

$functions = array(
    // Asignaciones
    'mod_assign_get_assignments', 'mod_assign_get_submissions', 'mod_assign_get_grades',
    'mod_assign_get_submission_status', 'mod_assign_list_participants',
    'mod_assign_save_grade', 'mod_assign_save_grades', 'mod_assign_save_user_extensions',
    'mod_assign_lock_submissions', 'mod_assign_unlock_submissions',
    'mod_assign_revert_submissions_to_draft',
    // Cursos y contenido
    // Nota: core_course_get_courses exige moodle/course:view (admin/sitio) en
    // Moodle 5.2; para teachers se usa core_webservice_get_site_info (id del
    // token user) + core_enrol_get_users_courses (cursos propios).
    'core_course_get_courses', 'core_course_get_contents',
    'core_webservice_get_site_info',
    'core_course_create_courses', 'core_course_update_courses', 'core_course_delete_courses',
    'core_course_create_categories', 'core_course_update_categories', 'core_course_delete_categories',
    'core_backup_get_copy_progress', 'core_backup_submit_copy_form',
    // Usuarios y matriculacion
    'core_user_get_users', 'core_user_create_users', 'core_user_update_users',
    'core_enrol_get_enrolled_users', 'core_enrol_get_users_courses',
    'enrol_manual_enrol_users', 'enrol_manual_unenrol_users',
    // Organizacion
    'core_group_get_course_groups', 'core_group_create_groups', 'core_group_add_group_members',
    'core_cohort_get_cohorts', 'core_cohort_create_cohorts', 'core_cohort_add_cohort_members',
    // Calendario
    'core_calendar_get_calendar_events', 'core_calendar_create_calendar_events',
    // Calificaciones
    'core_grades_update_grades', 'core_grading_save_definitions',
    'gradereport_user_get_grade_items',
    // Completado
    'core_completion_get_activities_completion_status',
    // Foros y comunicacion
    'mod_forum_get_forums_by_courses', 'mod_forum_get_forum_discussions',
    'mod_forum_add_discussion', 'mod_forum_add_discussion_post',
    'core_message_send_messages_to_conversation',
    // Informes
    'core_reportbuilder_list_reports',
    // Plugin local_mcpbridge (funciones PHP propias; el core no cubre el caso)
    'local_mcpbridge_create_activity', 'local_mcpbridge_copy_course',
    'local_mcpbridge_get_submission_text',
);

foreach ($functions as $functionname) {
    $exists = $DB->record_exists('external_functions', array('name' => $functionname));
    if (!$exists) {
        echo "WARN: funcion no existe en esta instalacion: $functionname\n";
        continue;
    }
    $record = array('externalserviceid' => $service->id, 'functionname' => $functionname);
    if (!$DB->record_exists('external_services_functions', $record)) {
        $DB->insert_record('external_services_functions', $record);
    }
}
echo "Funciones vinculadas al servicio.\n";

// --- 2. Usuarios de servicio ---
$course = $DB->get_record('course', array('shortname' => 'MCP2026'));
$coursecontext = context_course::instance($course->id);

// ws_docente: editingteacher en el curso (capability de calificar)
$docente = core_user::get_user_by_username('ws_docente');
if (!$docente) {
    $docente = create_user_record('ws_docente', 'Service123!', 'manual');
    $docente->firstname = 'WS'; $docente->lastname = 'Docente';
    $docente->email = 'ws_docente@example.local';
    user_update_user($docente, false);
    $docente = core_user::get_user_by_username('ws_docente');
}
enrol_try_internal_enrol($course->id, $docente->id, 3); // roleid 3 = editingteacher
echo "Usuario ws_docente listo (id={$docente->id}).\n";

// ws_lectura: non-editing teacher (ve notas, no califica)
$lectura = core_user::get_user_by_username('ws_lectura');
if (!$lectura) {
    $lectura = create_user_record('ws_lectura', 'Service123!', 'manual');
    $lectura->firstname = 'WS'; $lectura->lastname = 'Lectura';
    $lectura->email = 'ws_lectura@example.local';
    user_update_user($lectura, false);
    $lectura = core_user::get_user_by_username('ws_lectura');
}
enrol_try_internal_enrol($course->id, $lectura->id, 4); // roleid 4 = teacher (no editor)
echo "Usuario ws_lectura listo (id={$lectura->id}).\n";

// Los usuarios WS deben poder operar en CUALQUIER curso del sitio: los
// cursos creados via core_course_create_courses no incluyen a los tokens
// (chicken-and-egg: matricular exige capacidad en el contexto del curso),
// asi que se matriculan en todos los cursos reales (id > 1). Idempotente:
// enrol_try_internal_enrol no duplica matriculas.
foreach ($DB->get_records_select('course', 'id > 1') as $c) {
    enrol_try_internal_enrol($c->id, $docente->id, 3); // editingteacher
    enrol_try_internal_enrol($c->id, $lectura->id, 4);  // teacher (no editor)
}
echo "Usuarios WS matriculados en todos los cursos (id > 1).\n";

// --- 2.5 Rol de web service (webservice/rest:use en contexto de sistema) ---
// El protocolo REST exige esta capability para autenticar tokens permanentes.
$role = $DB->get_record('role', array('shortname' => 'webservice'));
if (!$role) {
    $roleid = create_role('Web services', 'webservice', 'Rol para usuarios de web services REST');
    assign_capability('webservice/rest:use', CAP_ALLOW, $roleid, context_system::instance());
    $role = $DB->get_record('role', array('shortname' => 'webservice'));
    echo "Rol webservice creado: id={$role->id}\n";
}
$syscontext = context_system::instance();
foreach (array($docente, $lectura) as $wsuser) {
    if (!$DB->record_exists('role_assignments', array('roleid' => $role->id, 'userid' => $wsuser->id, 'contextid' => $syscontext->id))) {
        role_assign($role->id, $wsuser->id, $syscontext);
    }
}
echo "Rol webservice asignado a ws_docente y ws_lectura.\n";

// --- 3. Tokens permanentes ---
function crear_token($serviceid, $userid) {
    global $DB;
    $existing = $DB->get_record('external_tokens',
        array('externalserviceid' => $serviceid, 'userid' => $userid, 'tokentype' => 0));
    if ($existing && $existing->token) {
        return $existing->token;
    }
    return external_generate_token(EXTERNAL_TOKEN_PERMANENT, $serviceid, $userid,
        context_system::instance());
}

$token_docente = crear_token($service->id, $docente->id);
$token_lectura = crear_token($service->id, $lectura->id);

echo "\n=== COPIAR ESTOS VALORES A .env ===\n";
echo "MOODLE_TOKEN_DOCENTE=$token_docente\n";
echo "MOODLE_TOKEN_LECTURA=$token_lectura\n";
echo "================================\n";
