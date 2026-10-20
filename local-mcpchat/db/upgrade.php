<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

// En Moodle 5.2 el hook de actualizacion (xmldb_local_mcpchat_upgrade) solo
// se ejecuta si vive aqui (db/upgrade.php); el upgrade.php raiz no se carga.
// Cargamos lib.php (API del usuario bot) y el upgrade.php raiz, donde esta
// definida la funcion, y la maquinaria de upgrade la invoca con $oldversion.
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/upgrade.php');
