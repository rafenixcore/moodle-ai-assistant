<?php
// This file is part of the local_mcpbridge plugin for Moodle.
namespace local_mcpbridge\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/helper/copy_helper.class.php');
require_once($CFG->libdir . '/externallib.php');

/**
 * Clona un curso via Web Service (backup/restore asincrono).
 *
 * Por que existe este WS: core_backup_submit_copy_form (el WS nativo) es
 * inusable con tokens permanentes en Moodle 5.2 — el copy_form de formslib
 * exige el marcador _qf__ y un sesskey valido de sesion, y el cliente WS
 * stateless no puede producirlo (falla con missingparam/copyformfail).
 * Aqui se hace el mismo trabajo que la UI (copy_helper::process_formdata +
 * create_copy) del lado servidor, con la sesion del token user.
 */
class copy_course extends \external_api {

    public static function execute_parameters() {
        return new \external_function_parameters(array(
            'courseid'  => new \external_value(PARAM_INT, 'Id del curso origen a clonar'),
            'fullname'  => new \external_value(PARAM_TEXT, 'Nombre completo del curso clonado'),
            'shortname' => new \external_value(PARAM_TEXT, 'Nombre corto unico del curso clonado'),
            'category'  => new \external_value(PARAM_INT, 'Categoria destino (default 1)', VALUE_DEFAULT, 1),
            'visible'   => new \external_value(PARAM_INT, 'Visible (default 1)', VALUE_DEFAULT, 1),
            'startdate' => new \external_value(PARAM_INT, 'Fecha de inicio (timestamp); 0 = manana', VALUE_DEFAULT, 0),
        ));
    }

    public static function execute_returns() {
        return new \external_single_structure(array(
            'backupid'  => new \external_value(PARAM_TEXT, 'Id del job de backup'),
            'restoreid' => new \external_value(PARAM_TEXT, 'Id del job de restore'),
            'courseid'  => new \external_value(PARAM_INT, 'Id del curso clonado (ya creado)'),
        ));
    }

    public static function execute($courseid, $fullname, $shortname,
                                   $category = 1, $visible = 1, $startdate = 0) {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'courseid', 'fullname', 'shortname', 'category', 'visible', 'startdate'));
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_all_capabilities(
            \core_course\management\helper::get_course_copy_capabilities(), $context);

        global $DB;

        // Misma validacion que el copy_form de la UI (shortname duplicado).
        $existente = $DB->get_record('course', array('shortname' => $params['shortname']),
                                     'fullname', IGNORE_MULTIPLE);
        if ($existente) {
            throw new \moodle_exception('shortnametaken', '', '', $existente->fullname);
        }
        if ($params['startdate'] == 0) {
            // Default del form de la UI: manana a medianoche (zona del usuario).
            $params['startdate'] = usergetmidnight(time()) + DAYSECS;
        }

        // Mismo flujo que la UI tras validar el form (copy.php): sin
        // formslib, sin sesskey — directo a copy_helper.
        $formdata = (object) array(
            'courseid'  => $params['courseid'],
            'fullname'  => $params['fullname'],
            'shortname' => $params['shortname'],
            'category'  => $params['category'],
            'visible'   => $params['visible'],
            'startdate' => $params['startdate'],
            'enddate'   => 0,
            'idnumber'  => '',
            'userdata'  => 0, // No copiar datos de usuarios (solo actividades).
        );
        $copydata = \copy_helper::process_formdata($formdata);
        $copyids = \copy_helper::create_copy($copydata);

        // create_copy() no devuelve el id del curso nuevo; el restore
        // controller lo tiene en backup_controllers (la columna es itemid,
        // no courseid: para TYPE_1COURSE el curso va en itemid).
        $newcourseid = $DB->get_field('backup_controllers', 'itemid',
            array('backupid' => $copyids['restoreid']), MUST_EXIST);

        return array('backupid'  => $copyids['backupid'],
                     'restoreid' => $copyids['restoreid'],
                     'courseid'  => $newcourseid);
    }
}
