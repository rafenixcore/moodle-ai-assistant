<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

$tasks = array(
    array(
        'classname' => 'local_mcpchat\task\process_message',
        'blocking' => 0,
    ),
);
