<?php
// This file is part of the local_mcpbridge plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

// Sin capabilities propias: la funcion WS se protege con la capability
// estandar moodle/course:manageactivities (la tienen los editores; los
// estudiantes no), declarada en db/services.php.
$capabilities = array();
