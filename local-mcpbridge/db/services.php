<?php
// This file is part of the local_mcpbridge plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

$functions = array(
    'local_mcpbridge_create_activity' => array(
        'classname'    => 'local_mcpbridge\external\create_activity',
        'methodname'   => 'execute',
        'description'  => 'Crea una actividad de curso (assign, page, resource, url, label)',
        'type'         => 'write',
        'capabilities' => 'moodle/course:manageactivities',
        'ajax'         => true,
    ),
    'local_mcpbridge_copy_course' => array(
        'classname'    => 'local_mcpbridge\external\copy_course',
        'methodname'   => 'execute',
        'description'  => 'Clona un curso (backup/restore asincrono) — reemplaza a core_backup_submit_copy_form, inusable por WS en Moodle 5.2 (sesskey de formslib)',
        'type'         => 'write',
        'capabilities' => 'moodle/backup:backupcourse, moodle/restore:restorecourse, moodle/course:create',
        'ajax'         => true,
    ),
    'local_mcpbridge_get_submission_text' => array(
        'classname'    => 'local_mcpbridge\external\get_submission_text',
        'methodname'   => 'execute',
        'description'  => 'Devuelve el onlinetext de una entrega (solo lectura) — mod_assign_get_submissions no expone el texto, solo fileareas',
        'type'         => 'read',
        'capabilities' => 'mod/assign:viewgrades',
        'ajax'         => true,
    ),
);
