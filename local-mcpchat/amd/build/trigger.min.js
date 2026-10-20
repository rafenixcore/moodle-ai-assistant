// This file is part of the local_mcpchat plugin for Moodle.
// Trigger inmediato del turno del bot: cuando el usuario envia un mensaje
// en el drawer de mensajeria (PubSub CONVERSATION_NEW_LAST_MESSAGE), se
// llama al WS ajax local_mcpchat_trigger_turno para que el bot responda
// al instante en vez de esperar al cron (que queda como fallback).
define([
    'core/ajax',
    'core/pubsub',
    'core_message/message_drawer_events',
], function(
    Ajax,
    PubSub,
    MessageDrawerEvents
) {

    /**
     * @param {Number} botid Id del usuario bot (asistente_ia).
     */
    function init(botid) {
        var enProceso = {}; // conversationid -> true (una peticion a la vez).

        PubSub.subscribe(MessageDrawerEvents.CONVERSATION_NEW_LAST_MESSAGE, function(conversation) {
            if (!conversation || !conversation.id) {
                return;
            }
            var members = conversation.members || [];
            var messages = conversation.messages || [];
            var botEnLaConversacion = false;
            for (var m = 0; m < members.length; m++) {
                if (parseInt(members[m].userid, 10) === parseInt(botid, 10)) {
                    botEnLaConversacion = true;
                    break;
                }
            }
            if (!botEnLaConversacion) {
                return; // Conversacion sin el bot: nada que disparar.
            }
            // El evento tambien llega cuando el poll trae un mensaje nuevo
            // (p. ej. la respuesta del bot): solo reaccionar a mensajes del
            // usuario, nunca a los del bot (guard anti-loops).
            if (!messages.length) {
                return;
            }
            var ultimo = messages[messages.length - 1];
            if (!ultimo || parseInt(ultimo.useridfrom, 10) === parseInt(botid, 10)) {
                return;
            }
            var messageid = parseInt(ultimo.id, 10);
            if (!messageid || enProceso[conversation.id]) {
                return; // Turno en curso; el siguiente mensaje re-dispara.
            }
            enProceso[conversation.id] = true;

            Ajax.call([{
                methodname: 'local_mcpchat_trigger_turno',
                args: {
                    conversationid: conversation.id,
                    messageid: messageid,
                },
            }]).then(function() {
                // Procesado (o rechazado con motivo conocido): el cron o el
                // proximo mensaje cubren el resto. Nada que mostrar al
                // usuario: la respuesta del bot llegara por el poll.
            }).catch(function() {
                // Fallo del WS (red, sesion...): silencio — el cron procesara
                // el mensaje igualmente. Solo log para consola de desarrollo.
                if (window.console && window.console.log) {
                    window.console.log('local_mcpchat: trigger_turno no disponible, cron cubre el turno');
                }
            }).always(function() {
                delete enProceso[conversation.id];
            });
        });
    }

    return {
        init: init,
    };
});
