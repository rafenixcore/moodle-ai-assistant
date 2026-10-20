<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

$observers = array(
    array(
        'eventname' => '\core\event\message_sent',
        'callback' => '\local_mcpchat\observer::message_sent',
    ),
);
