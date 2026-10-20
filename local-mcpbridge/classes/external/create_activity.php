<?php
// This file is part of the local_mcpbridge plugin for Moodle.
namespace local_mcpbridge\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php'); // add_moduleinfo().
require_once($CFG->libdir . '/externallib.php');

/**
 * Crea una actividad de curso via Web Service: assign, page, resource, url o label.
 */
class create_activity extends \external_api {

    public static function execute_parameters() {
        return new \external_function_parameters(array(
            'courseid' => new \external_value(PARAM_INT, 'Id del curso destino'),
            'type'     => new \external_value(PARAM_ALPHA, 'assign|page|resource|url|label'),
            'name'     => new \external_value(PARAM_TEXT, 'Nombre visible de la actividad'),
            'intro'    => new \external_value(PARAM_RAW, 'Descripcion/instrucciones', VALUE_DEFAULT, ''),
            'section'  => new \external_value(PARAM_INT, 'Numero de seccion', VALUE_DEFAULT, 1),
            // Opcionales por tipo (assign):
            'duedate'  => new \external_value(PARAM_INT, 'Fecha limite (timestamp)', VALUE_DEFAULT, 0),
            'allowsubmissionsfromdate' => new \external_value(PARAM_INT, 'Apertura (timestamp)', VALUE_DEFAULT, 0),
            'grade'    => new \external_value(PARAM_INT, 'Nota maxima (assign)', VALUE_DEFAULT, 100),
            // Opcional para resource/url:
            'content'  => new \external_value(PARAM_RAW, 'Contenido (url completa para type=url)', VALUE_DEFAULT, ''),
        ));
    }

    public static function execute_returns() {
        return new \external_single_structure(array(
            'cmid'     => new \external_value(PARAM_INT),
            'instance' => new \external_value(PARAM_INT),
            'modname'  => new \external_value(PARAM_TEXT),
        ));
    }

    public static function execute($courseid, $type, $name, $intro = '',
                                   $section = 1, $duedate = 0,
                                   $allowsubmissionsfromdate = 0, $grade = 100,
                                   $content = '') {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'courseid', 'type', 'name', 'intro', 'section', 'duedate',
            'allowsubmissionsfromdate', 'grade', 'content'));
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        global $DB;

        $course = get_course($courseid);
        $moduleinfo = (object) array(
            'modulename' => $type,
            // Moodle 5.x: add_moduleinfo() lee $moduleinfo->module (id de
            // la tabla modules), no el nombre; sin el, 'Column module
            // cannot be null'.
            'module'     => $DB->get_field('modules', 'id', array('name' => $type)),
            'section'    => $section,
            'visible'    => 1,
            'name'       => $name,
            'intro'      => $intro,
            'introformat'=> FORMAT_HTML,
            'course'     => $courseid,
        );

        if ($type === 'assign') {
            $moduleinfo->grade = $grade;
            $moduleinfo->duedate = $duedate;
            $moduleinfo->cutoffdate = 0;
            $moduleinfo->allowsubmissionsfromdate = $allowsubmissionsfromdate;
            $moduleinfo->assignsubmission_file_enabled = 1;
            $moduleinfo->assignfeedback_comments_enabled = 1;
            // Campos requeridos por assign_add_instance() en Moodle 5.x
            // (la tabla mdl_assign los tiene NOT NULL sin default).
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
        }
        if ($type === 'url') {
            $moduleinfo->externalurl = $content;
        }
        if ($type === 'resource') {
            $moduleinfo->files = file_get_unused_draft_itemid();
            $moduleinfo->content = $content; // Texto plano; el archivo real via draft files en v2.
        }

        // add_moduleinfo() devuelve el moduleinfo actualizado, no el cmid.
        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        $cmid = $moduleinfo->coursemodule;
        list($course2, $cm) = get_course_and_cm_from_cmid($cmid, $type);

        return array('cmid' => $cmid, 'instance' => $cm->instance, 'modname' => $type);
    }
}
