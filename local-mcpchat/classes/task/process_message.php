<?php
// This file is part of the local_mcpchat plugin for Moodle.
namespace local_mcpchat\task;

defined('MOODLE_INTERNAL') || die();

// lib.php no se autoloada en Moodle 5.2; aqui viven las funciones del plugin
// (local_mcpchat_get_bot_userid).
require_once(__DIR__ . '/../../lib.php');

/**
 * Procesa un mensaje del chat del bot (corre en cron).
 * Actua con la identidad del remitente (set_user): los permisos de Moodle
 * son la barrera para todo lo que el bot ejecute.
 */
class process_message extends \core\task\adhoc_task {

    /**
     * Ejecuta el turno del bot para el mensaje referenciado en el customdata.
     * Actua con la identidad del remitente (set_user): los permisos de Moodle
     * son la barrera para todo lo que el bot ejecute.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->messageid) || empty($data->conversationid)) {
            return;
        }
        $message = $DB->get_record('messages', array('id' => (int) $data->messageid));
        if (!$message) {
            return;
        }
        $senderid = (int) $message->useridfrom;
        $botid = \local_mcpchat_get_bot_userid();
        if (!$botid || $senderid === $botid) {
            return;
        }
        $sender = \core_user::get_user($senderid);
        if (!$sender || $sender->deleted) {
            return;
        }

        // Ejecutar como el remitente: toda capability se evalua contra el.
        \core\session\manager::set_user($sender);
        $bot = new \local_mcpchat\bot((int) $data->conversationid, $senderid, $botid);
        try {
            // El messageid identifica el mensaje que disparo el turno (el
            // observer lo actualiza al ultimo si llegan varios antes del
            // cron); se usa para resolver la confirmacion pendiente.
            $bot->run((int) ($data->messageid ?? 0));
        } catch (\Throwable $e) {
            // No dejar que la task falle en silencio: registrar y avisar.
            debugging('local_mcpchat: error en turno del bot: ' . $e->getMessage(),
                DEBUG_NORMAL);
        }
    }
}
