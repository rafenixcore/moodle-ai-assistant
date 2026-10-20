<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

/**
 * WS ajax para el disparo inmediato del turno del bot desde el navegador
 * (trigger.js -> local_mcpchat_trigger_turno). El cron queda como fallback.
 */
$functions = array(
    'local_mcpchat_trigger_turno' => array(
        'classname'    => 'local_mcpchat\external\trigger_turno',
        'methodname'   => 'execute',
        'description'  => 'Dispara el turno del bot para el ultimo mensaje de una conversacion (ajax del drawer de mensajeria)',
        'type'         => 'write',
        'capabilities' => 'local/mcpchat:use',
        'ajax'         => true,
    ),
);
