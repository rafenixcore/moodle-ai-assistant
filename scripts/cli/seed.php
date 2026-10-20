<?php
// Este archivo se monta en /var/www/html/local/seedscripts/cli/seed.php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php'); // add_moduleinfo() en Moodle 5.x
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php'); // constantes ASSIGN_SUBMISSION_STATUS_*
require_once($CFG->dirroot . '/enrol/manual/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

$admin = get_admin();
\core\session\manager::set_user($admin);

const DAY = 86400;

// 1. Curso de pruebas
$course = $DB->get_record('course', array('shortname' => 'MCP2026'));
if (!$course) {
    $course = create_course((object) array(
        'fullname' => 'Pruebas MCP 2026',
        'shortname' => 'MCP2026',
        'category' => 1,
        'summary' => 'Curso semilla para probar el servidor MCP.',
        'summaryformat' => FORMAT_HTML,
        'format' => 'topics',
        'numsections' => 3,
        'visible' => 1,
    ));
    echo "Curso creado: id={$course->id}\n";
} else {
    echo "Curso existente: id={$course->id}\n";
}

// 2. Estudiantes
$users = array();
for ($i = 1; $i <= 10; $i++) {
    $username = "student$i";
    $user = $DB->get_record('user', array('username' => $username));
    if (!$user) {
        $user = create_user_record($username, 'Password123!', 'manual');
        $user->firstname = 'Estudiante';
        $user->lastname = sprintf('%02d', $i);
        $user->email = "$username@example.local";
        $user->country = 'ES';
        $user->lang = 'es';
        user_update_user($user, false);
        $user = core_user::get_user_by_username($username);
    }
    $users[] = $user;
    // Matricular como estudiante (roleid 5) en el curso
    if (!is_enrolled(context_course::instance($course->id), $user->id)) {
        enrol_try_internal_enrol($course->id, $user->id, 5);
    }
}
echo "Estudiantes: " . count($users) . "\n";

// 3. Asignaciones
function crear_assign($course, $name, $cmidnumber, $days_due, $intro) {
    global $DB;
    $cm = $DB->get_record('course_modules', array('course' => $course->id, 'idnumber' => $cmidnumber));
    if ($cm) {
        return $cm->instance;
    }
    $moduleinfo = new stdClass();
    $moduleinfo->modulename = 'assign';
    $moduleinfo->module = $DB->get_field('modules', 'id', array('name' => $moduleinfo->modulename));
    $moduleinfo->section = 1;
    $moduleinfo->visible = 1;
    $moduleinfo->cmidnumber = $cmidnumber;
    $moduleinfo->name = $name;
    $moduleinfo->intro = $intro;
    $moduleinfo->introformat = FORMAT_HTML;
    $moduleinfo->grade = 100;                     // nota maxima
    $moduleinfo->allowsubmissionsfromdate = time() - 14 * DAY;
    $moduleinfo->duedate = time() + $days_due * DAY;
    $moduleinfo->cutoffdate = 0;
    $moduleinfo->assignsubmission_file_enabled = 1;
    $moduleinfo->assignsubmission_file_maxfiles = 1;
    $moduleinfo->assignfeedback_comments_enabled = 1;
    // Propiedades requeridas por assign_add_instance() en Moodle 5.x (sin defaults).
    $moduleinfo->submissiondrafts = 0;
    $moduleinfo->requiresubmissionstatement = 0;
    $moduleinfo->sendnotifications = 0;
    $moduleinfo->sendlatenotifications = 0;
    $moduleinfo->gradingduedate = 0;
    $moduleinfo->teamsubmission = 0;
    $moduleinfo->requireallteammemberssubmit = 0;
    $moduleinfo->blindmarking = 0;
    $moduleinfo->markingworkflow = 0;
    $moduleinfo->markingallocation = 0;
    $moduleinfo->course = $course->id;
    $moduleinfo->coursemodule = 0;
    $moduleinfo = add_moduleinfo($moduleinfo, $course);
    $cmid = $moduleinfo->coursemodule;
    list($course2, $cm) = get_course_and_cm_from_cmid($cmid, 'assign');
    echo "Assign creada: '{$name}' (cmid=$cmid, instance={$cm->instance})\n";
    return $cm->instance;
}

$a1 = crear_assign($course, 'Informe inicial', 'mcp-a1', -7,
    'Entrega del informe inicial. <strong>Vencida hace una semana.</strong>');
$a2 = crear_assign($course, 'Proyecto parcial', 'mcp-a2', 7,
    'Avance del proyecto. Entrega dentro de una semana.');
$a3 = crear_assign($course, 'Exposicion final', 'mcp-a3', 30,
    'Trabajo final con exposicion.');

// 4. Entregas con estados variados
// $duedateoffset: dias relativos a la duedate de la assign (negativo = antes). Fechas deterministas.
function entregar($course, $assignid, $user, $status, $filename, $duedateoffset = 0) {
    global $DB;
    $cm = get_coursemodule_from_instance('assign', $assignid, $course->id);
    $context = context_module::instance($cm->id);
    $assign = new assign($context, $cm, $course);
    $submission = $assign->get_user_submission($user->id, true);
    if ($submission) {
        $submission->status = $status; // ASSIGN_SUBMISSION_STATUS_SUBMITTED o DRAFT
        $t = $assign->get_instance()->duedate + $duedateoffset * DAY;
        $submission->timecreated = $t;
        $submission->timemodified = $t;
        // update_submission() es protected en Moodle 5.x; se replica su logica (update_record).
        $DB->update_record('assign_submission', $submission);
    }
    if ($filename) {
        $fs = get_file_storage();
        $filerecord = array(
            'contextid' => $context->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => $filename,
        );
        if (!$fs->file_exists($context->id, 'assignsubmission_file', 'submission_files', $submission->id, '/', $filename)) {
            $fs->create_file_from_string($filerecord,
                "Entrega simulada de {$user->firstname} {$user->lastname}.\n" .
                "Este archivo fue generado por el seed para pruebas del MCP.\n");
        }
    }
    return $submission;
}

function calificar($course, $assignid, $user, $gradevalue, $comment) {
    global $DB;
    $cm = get_coursemodule_from_instance('assign', $assignid, $course->id);
    $context = context_module::instance($cm->id);
    $assign = new assign($context, $cm, $course);
    $data = new stdClass();
    $data->attemptnumber = 0; // Requerido por save_grade() en Moodle 5.x (null rompe get_user_submission).
    $data->grade = (string) $gradevalue;
    $data->assignfeedbackcomments_editor = array(
        'text' => $comment,
        'format' => FORMAT_HTML,
    );
    // Moodle 5.x: save_grade($userid, $data) (en versiones anteriores era ($data, $userid)).
    $assign->save_grade($user->id, $data);
    // Fecha determinista de calificacion: unos dias despues de la entrega.
    $submission = $assign->get_user_submission($user->id, false);
    $gradetime = ($submission ? $submission->timemodified : time()) + 2 * DAY;
    $grade = $assign->get_user_grade($user->id, false);
    if ($grade) {
        $grade->timecreated = $gradetime;
        $grade->timemodified = $gradetime;
        $DB->update_record('assign_grades', $grade);
    }
    echo "Calificado: {$user->username} en assign {$assignid}: {$gradevalue}\n";
}

// A1 (vencida): 4 entregados con archivo (tardios: duedate + 1 dia), 1 borrador (duedate - 3 dias), 5 sin entregar
foreach (array(0, 1, 2, 3) as $i) {
    entregar($course, $a1, $users[$i], ASSIGN_SUBMISSION_STATUS_SUBMITTED, "informe_student" . ($i + 1) . ".pdf", 1);
}
entregar($course, $a1, $users[4], ASSIGN_SUBMISSION_STATUS_DRAFT, null, -3);

// A2 (por vencer): 6 entregados a tiempo (duedate - 3 dias), 1 borrador (duedate - 5 dias), 3 sin entregar
foreach (array(0, 1, 2, 3, 4, 5) as $i) {
    entregar($course, $a2, $users[$i], ASSIGN_SUBMISSION_STATUS_SUBMITTED, "avance_student" . ($i + 1) . ".pdf", -3);
}
entregar($course, $a2, $users[6], ASSIGN_SUBMISSION_STATUS_DRAFT, null, -5);

// A3 (calificada): 4 entregados a tiempo (duedate - 10 dias) y calificados unos dias despues
$notas = array(88 => 'Excelente analisis y estructura clara.',
               74 => 'Buen trabajo, revisar las conclusiones.',
               61 => 'Aprobado justo: falta profundidad en la metodologia.',
               95 => 'Sobresaliente, muy completo.');
$i = 0;
foreach ($notas as $nota => $comentario) {
    entregar($course, $a3, $users[$i], ASSIGN_SUBMISSION_STATUS_SUBMITTED, "final_student" . ($i + 1) . ".pdf", -10);
    calificar($course, $a3, $users[$i], $nota, $comentario);
    $i++;
}

echo "Seed completado.\n";
