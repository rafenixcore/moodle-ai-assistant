<?php
// This file is part of the local_mcpchat plugin for Moodle.
namespace local_mcpchat\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
// En el path ajax (lib/ajax/service.php) el lib.php del plugin NO se carga
// automaticamente (en CLI lo hacia el observer del envio, en el mismo
// proceso): sin esto, local_mcpchat_get_bot_userid() no existe -> el turno
// falla en silencio en el navegador. Cargarlo explicitamente cubre el WS
// completo (bot::run() incluido).
require_once(\core_component::get_component_directory('local_mcpchat') . '/lib.php');

/**
 * WS ajax local_mcpchat_trigger_turno: dispara el turno del bot al instante
 * desde el navegador (el cron queda como fallback si el JS no corre).
 *
 * Anti doble-ejecucion con el cron: la adhoc task se procesa bajo el mismo
 * lock que usa el manager de cron ('adhoc_' . taskid, factory 'cron'), se
 * re-lee la fila tras tomarlo (el observer pudo actualizar el customdata) y
 * se borra la task ANTES de run() (si llega un mensaje nuevo durante el run,
 * el observer encola una task nueva: auto-curacion).
 *
 * Sin set_user: en el path ajax $USER ya es el remitente del mensaje (el
 * guard valida que el mensaje es suyo), asi que bot::run() evalua las
 * capabilities contra el — los permisos de Moodle son la barrera.
 */
class trigger_turno extends \external_api {

    public static function execute_parameters() {
        return new \external_function_parameters(array(
            'conversationid' => new \external_value(PARAM_INT, 'Id de la conversacion'),
            'messageid'      => new \external_value(PARAM_INT, 'Id del mensaje que dispara el turno'),
        ));
    }

    public static function execute_returns() {
        return new \external_single_structure(array(
            'procesado' => new \external_value(PARAM_BOOL, 'true si el turno se proceso aqui'),
            'motivo'    => new \external_value(PARAM_TEXT, 'Motivo si no se proceso (sin_tarea, mensaje_del_bot, cron_en_proceso, error_reencolado, ...)',
                VALUE_OPTIONAL, ''),
        ));
    }

    public static function execute(int $conversationid, int $messageid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), array(
            'conversationid' => $conversationid,
            'messageid'      => $messageid,
        ));
        $conversationid = $params['conversationid'];
        $messageid = $params['messageid'];

        self::validate_context(\context_system::instance());
        require_capability('local/mcpchat:use', \context_system::instance());
        // Un turno puede tardar (DeepSeek + tools): no matar el request ajax.
        // OJO: en 5.2 la clase es core_php_time_limit (global, sin namespace).
        \core_php_time_limit::raise(120);

        $botid = \local_mcpchat_get_bot_userid();
        if (!$botid) {
            return array('procesado' => false, 'motivo' => 'sin_bot');
        }

        // Guards del messageid del cliente (solo validacion; el messageid
        // autoritativo para run() es el del customdata, re-leido tras el lock).
        $msg = $DB->get_record('messages', array('id' => $messageid));
        if (!$msg || (int) $msg->conversationid !== $conversationid) {
            return array('procesado' => false, 'motivo' => 'mensaje_invalido');
        }
        if ((int) $msg->useridfrom === $botid) {
            return array('procesado' => false, 'motivo' => 'mensaje_del_bot');
        }
        if ((int) $msg->useridfrom !== (int) $USER->id) {
            return array('procesado' => false, 'motivo' => 'mensaje_ajeno');
        }

        // Buscar la task encolada de esa conversacion (mismo patron LIKE del
        // observer; el customdata termina con "conversationid":N}).
        $like = $DB->sql_like('customdata', ':like');
        $task = $DB->get_record_select('task_adhoc',
            "classname = :cls AND $like",
            array('cls' => '\\local_mcpchat\\task\\process_message',
                  'like' => '%"conversationid":' . $conversationid . '}%'));
        if (!$task) {
            // Sin tarea encolada: ya la proceso el cron (o nunca existio).
            return array('procesado' => false, 'motivo' => 'sin_tarea');
        }

        // Exclusión mutua con el cron: mismo lock que \core\task\manager
        // (get_lock('adhoc_' . taskid, 0) antes de ejecutar la task).
        $lockfactory = \core\lock\lock_config::get_lock_factory('cron');
        $lock = $lockfactory->get_lock('adhoc_' . $task->id, 0);
        if (!$lock) {
            return array('procesado' => false, 'motivo' => 'cron_en_proceso');
        }

        try {
            // Re-leer la fila tras el lock: el observer pudo actualizar el
            // customdata al ultimo mensaje mientras esperabamos el lock.
            $task = $DB->get_record('task_adhoc', array('id' => $task->id));
            if (!$task) {
                return array('procesado' => false, 'motivo' => 'sin_tarea');
            }
            $data = json_decode((string) $task->customdata, false);
            $runmessageid = isset($data->messageid) ? (int) $data->messageid : $messageid;

            // Borrar ANTES de ejecutar: si entra un mensaje nuevo durante el
            // run, el observer encola una task nueva (auto-curacion) y el
            // siguiente turno (o el cron) lo atiende.
            \core\task\manager::delete_adhoc_task((int) $task->id);

            $bot = new \local_mcpchat\bot($conversationid, (int) $msg->useridfrom, $botid);
            $bot->run($runmessageid);
            return array('procesado' => true, 'motivo' => '');
        } catch (\Throwable $e) {
            // Nunca dejar el turno huerfano: re-encolar (el cron lo reintenta)
            // y reportar el fallo al JS (silencio = el usuario veria la
            // respuesta nunca llegar; el cron cubre el path de red, pero el
            // error debe quedar visible en consola).
            $reatask = new \local_mcpchat\task\process_message();
            $reatask->set_custom_data(array(
                'messageid' => isset($data->messageid) ? (int) $data->messageid : $messageid,
                'conversationid' => $conversationid,
            ));
            \core\task\manager::queue_adhoc_task($reatask);
            debugging('local_mcpchat: trigger_turno fallo, reencolado: ' . $e->getMessage(),
                DEBUG_NORMAL);
            return array('procesado' => false, 'motivo' => 'error_reencolado');
        } finally {
            $lock->release();
        }
    }
}
