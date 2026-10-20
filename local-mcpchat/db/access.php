<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'local/mcpchat:use' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ),
    ),
);
