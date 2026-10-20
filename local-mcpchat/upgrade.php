<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

function xmldb_local_mcpchat_install() {
    // El usuario bot se crea con las APIs completas (el install corre antes
    // de que todo el core esté disponible; upgrade() cubre ambos casos).
    local_mcpchat_asegurar_usuario_bot();
    return true;
}

function xmldb_local_mcpchat_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();
    if ($oldversion < 2026082100) {
        // Instalacion sobre un plugin ya presente (sin install.xml corrido):
        // asegurar el esquema de tablas.
        if (!$dbman->table_exists('local_mcpchat_audit')) {
            $dbman->install_from_xmldb_file(__DIR__ . '/db/install.xml');
        }
        local_mcpchat_asegurar_usuario_bot();
    }
    return true;
}
