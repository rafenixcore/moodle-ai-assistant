<?php
// e2e Task 2: mensaje del profesor al bot -> cron -> respuesta estatica.
// Uso: docker compose exec -T web php /var/www/html/local/seedscripts/e2e/mcpchat/t2_cadena.php
define('CLI_SCRIPT', true);
require_once('/var/www/html/public/config.php');

$teacher = core_user::get_user_by_username('admin');
$bot = core_user::get_user_by_username('asistente_ia');
if (!$teacher || !$bot) {
    fwrite(STDERR, "FALTA admin o asistente_ia\n");
    exit(1);
}

// Conversacion directa admin<->bot, idempotente.
$sql = "SELECT mc.id FROM {message_conversations} mc
        JOIN {message_conversation_members} m1 ON m1.conversationid = mc.id AND m1.userid = :u1
        JOIN {message_conversation_members} m2 ON m2.conversationid = mc.id AND m2.userid = :u2
        WHERE mc.type = :t LIMIT 1";
$conv = $DB->get_field_sql($sql, array(
    'u1' => $teacher->id, 'u2' => $bot->id,
    't' => \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL));
if (!$conv) {
    $created = \core_message\api::create_conversation(
        \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
        array($teacher->id, $bot->id));
    $conv = $created->id;
}
echo "Conversacion: $conv\n";

// El admin debe poder recibir respuestas del bot (privacidad): con
// messagingallusers desactivado (Moodle 5.2), el bot solo puede escribir a
// sus contactos o a quien comparte curso con el; admin y bot no comparten
// cursos, asi que se establecen como contactos.
if (!\core_message\api::is_contact($bot->id, $teacher->id)) {
    \core_message\api::create_contact_request($bot->id, $teacher->id);
    \core_message\api::confirm_contact_request($bot->id, $teacher->id);
}

// Enviar mensaje (dispara message_sent -> observer -> adhoc task).
$sent = \core_message\api::send_message_to_conversation(
    $teacher->id, $conv, 'hola', FORMAT_MOODLE);
echo "Mensaje enviado.\n";

// Correr el cron para ejecutar la adhoc task.
passthru('php /var/www/html/admin/cli/cron.php > /dev/null 2>&1');

// Verificar la respuesta del bot: solo respuestas posteriores al mensaje
// enviado en esta ejecucion (una respuesta vieja de un run anterior no
// debe dar PASS).
$msgs = \core_message\api::get_conversation_messages(
    $teacher->id, $conv, 0, 5, 'timecreated DESC');
$respuesta = null;
foreach ($msgs['messages'] as $m) {
    if ((int) $m->useridfrom === (int) $bot->id &&
            (int) $m->timecreated >= (int) $sent->timecreated) {
        $respuesta = $m->text;
        break;
    }
}
if ($respuesta === null) {
    fwrite(STDERR, "FAIL: el bot no respondio\n");
    exit(1);
}
echo "RESPUESTA: " . substr($respuesta, 0, 200) . "\n";
// La asercion verifica la CADENA mensaje->cron->respuesta con el
// orquestador nuevo (Task 5): el texto exacto depende del estado del
// sitio (sin key: bot_no_configurado; con key real: respuesta del LLM),
// asi que basta con que el bot haya respondido en la ventana del turno.
if (trim($respuesta) === '') {
    fwrite(STDERR, "FAIL: respuesta inesperada\n");
    exit(1);
}
echo "PASS: cadena mensaje->cron->respuesta OK\n";
