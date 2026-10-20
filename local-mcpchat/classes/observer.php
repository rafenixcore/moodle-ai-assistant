<?php
// This file is part of the local_mcpchat plugin for Moodle.
namespace local_mcpchat;

defined('MOODLE_INTERNAL') || die();

// lib.php no se autoloada en Moodle 5.2; aqui viven las funciones del plugin
// (local_mcpchat_get_bot_userid).
require_once(__DIR__ . '/../lib.php');

/**
 * Observa \core\event\message_sent. Si el mensaje va dirigido al bot y el
 * remitente tiene local/mcpchat:use, encola la adhoc task que lo procesa.
 *
 * Nota Moodle 5.2: el evento no trae conversationid (manager.php dispara
 * con create_from_ids(userfrom, userto, messageid, courseid)); el
 * conversationid se lee de la tabla messages por el messageid.
 */
class observer {

    /**
     * Maneja \core\event\message_sent: si el mensaje va dirigido al bot y el
     * remitente tiene local/mcpchat:use, encola la adhoc task que lo procesa.
     *
     * @param \core\event\message_sent $event El evento disparado al enviar el mensaje.
     */
    public static function message_sent(\core\event\message_sent $event): void {
        global $DB;

        $botid = \local_mcpchat_get_bot_userid();
        if (!$botid) {
            return;
        }
        // Mensajes del propio bot: ignorar (evita loops).
        if ((int) $event->userid === $botid) {
            return;
        }
        // Solo mensajes directos cuyo destinatario es el bot.
        if ((int) $event->relateduserid !== $botid) {
            return;
        }
        $messageid = (int) $event->objectid;
        $conversationid = (int) $DB->get_field('messages', 'conversationid',
            array('id' => $messageid));
        if (!$conversationid) {
            return;
        }
        // Puerta de entrada: solo quien tiene la capability.
        if (!has_capability('local/mcpchat:use', \context_system::instance(),
                (int) $event->userid)) {
            return;
        }
        // Si ya hay una task en cola para esa conversacion, el ULTIMO
        // mensaje manda: se actualiza su customdata al messageid nuevo en
        // vez de encolar otra (mensajes rapidos no deben responder fuera
        // de orden, y el turno debe reflejar la intencion final del
        // usuario). Nota: no se usa reschedule_or_queue_adhoc_task() porque
        // matchea el customdata EXACTO (el messageid cambia) y encolaria
        // una task duplicada; se actualiza la fila directamente. El
        // customdata termina con el conversationid como ultima clave, asi
        // que el patron se delimita con '}' para evitar colisiones por
        // prefijo (ej.: "conversationid":3 no debe matchear
        // "conversationid":30).
        $like = $DB->sql_like('customdata', ':like');
        $encolada = $DB->get_record_select('task_adhoc',
            "classname = :cls AND $like",
            array('cls' => '\\local_mcpchat\\task\\process_message',
                  'like' => '%"conversationid":' . $conversationid . '}%'));
        if ($encolada) {
            $DB->set_field('task_adhoc', 'customdata',
                json_encode(array('messageid' => $messageid,
                                  'conversationid' => $conversationid)),
                array('id' => $encolada->id));
            return;
        }

        $task = new \local_mcpchat\task\process_message();
        $task->set_custom_data(array(
            'messageid' => $messageid,
            'conversationid' => $conversationid,
        ));
        \core\task\manager::queue_adhoc_task($task);
    }
}
