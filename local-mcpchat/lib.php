<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

/**
 * Crea/asegura el usuario bot "asistente_ia" (auth none, sin login) y su
 * privacidad de mensajeria ("cualquiera puede escribirle": sin eso un
 * profesor que no comparte curso no puede iniciar la conversacion).
 * Idempotente.
 */
function local_mcpchat_asegurar_usuario_bot(): int {
    $bot = core_user::get_user_by_username('asistente_ia');
    if (!$bot) {
        $bot = create_user_record('asistente_ia', 'login-bloqueado', 'none');
        $bot->firstname = 'Asistente';
        $bot->lastname = 'IA';
        $bot->email = 'asistente-ia@example.local';
        $bot->confirmed = 1;
        $bot->suspended = 0;
        user_update_user($bot, false);
        $bot = core_user::get_user_by_username('asistente_ia');
    }
    set_user_preferences(array('message_blocknoncontacts' => 0), $bot->id);
    return (int) $bot->id;
}

/**
 * Id del usuario bot (0 si no existe aun).
 */
function local_mcpchat_get_bot_userid(): int {
    $bot = core_user::get_user_by_username('asistente_ia');
    return $bot ? (int) $bot->id : 0;
}

/**
 * Hook legacy before_standard_html_head (Moodle 5.2 lo invoca SIN argumentos;
 * se usa global $PAGE): carga el modulo AMD que dispara el turno del bot al
 * instante cuando el usuario envia un mensaje en el drawer de mensajeria.
 *
 * Guardas: solo usuarios logueados (no invitados) con la capability del
 * plugin y solo si el bot existe. El cron queda como fallback si este JS no
 * corre.
 */
function local_mcpchat_before_standard_html_head(): string {
    global $PAGE;
    if (!isloggedin() || isguestuser()) {
        return '';
    }
    if (!has_capability('local/mcpchat:use', context_system::instance())) {
        return '';
    }
    $botid = local_mcpchat_get_bot_userid();
    if (!$botid) {
        return '';
    }
    $PAGE->requires->js_call_amd('local_mcpchat/trigger', 'init', array($botid));
    return '';
}
