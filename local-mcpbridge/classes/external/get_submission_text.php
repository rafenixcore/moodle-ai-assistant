<?php
// This file is part of the local_mcpbridge plugin for Moodle.
namespace local_mcpbridge\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Devuelve el texto de una entrega (submission) para el flujo resumir_entrega.
 *
 * Por que existe este WS: mod_assign_get_submissions solo expone las
 * fileareas de los plugins de entrega (los archivos), no el texto del
 * onlinetext — ese vive en assignsubmission_onlinetext y el WS nativo no
 * lo devuelve. Aqui se lee la tabla directamente.
 *
 * Es SOLO LECTURA: no genera ni guarda feedback. La generacion del resumen
 * es responsabilidad del cliente IA (el servidor MCP no duplica logica de
 * IA, YAGNI) y el guardado se hace con mod_assign_save_grade (feedback por
 * el plugin de comentarios, assignfeedback_comments).
 */
class get_submission_text extends \external_api {

    public static function execute_parameters() {
        return new \external_function_parameters(array(
            'submissionid' => new \external_value(PARAM_INT, 'Id de la submission (campo submission_id de listar_entregas)'),
        ));
    }

    public static function execute_returns() {
        return new \external_single_structure(array(
            'submissionid' => new \external_value(PARAM_INT),
            'assignmentid' => new \external_value(PARAM_INT),
            'userid'       => new \external_value(PARAM_INT),
            'status'       => new \external_value(PARAM_TEXT),
            'onlinetext'   => new \external_value(PARAM_RAW, 'Texto de la entrega (vacio si es solo archivo o no hay)'),
        ));
    }

    public static function execute($submissionid) {
        $params = self::validate_parameters(self::execute_parameters(), compact('submissionid'));

        global $DB;

        $submission = $DB->get_record('assign_submission', array('id' => $params['submissionid']), '*', MUST_EXIST);
        $assign = $DB->get_record('assign', array('id' => $submission->assignment), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $assign->course), 'id', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        // El docente del curso puede ver el texto de las entregas.
        require_capability('mod/assign:viewgrades', $context);

        $text = '';
        if ($submission->status === 'submitted') {
            $onlinetext = $DB->get_record('assignsubmission_onlinetext',
                                          array('submission' => $submission->id));
            if ($onlinetext) {
                $text = $onlinetext->onlinetext;
            }
        }

        return array(
            'submissionid' => $submission->id,
            'assignmentid' => $submission->assignment,
            'userid'       => $submission->userid,
            'status'       => $submission->status,
            'onlinetext'   => $text,
        );
    }
}
