<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Asistente IA de gestión (local_mcpchat)';
$string['apikey'] = 'API key de DeepSeek';
$string['apikey_desc'] = 'Key de la API de DeepSeek (https://platform.deepseek.com). El bot la usa para el chat con tools.';
$string['model'] = 'Modelo';
$string['model_desc'] = 'Modelo de DeepSeek (default: deepseek-v4-flash).';
$string['systemprompt'] = 'Prompt del sistema';
$string['systemprompt_desc'] = 'Instrucciones de comportamiento del bot.';
$string['maxmsgsday'] = 'Mensajes máximos por usuario y día';
$string['maxmsgsday_desc'] = 'Límite anti-abuso/costo por usuario (0 = sin límite).';
$string['contextomsgs'] = 'Mensajes de contexto';
$string['contextomsgs_desc'] = 'Cuántos mensajes recientes de la conversación se envían al modelo.';
$string['riesgosas'] = 'Tools que requieren confirmación';
$string['riesgosas_desc'] = 'Lista separada por comas. Antes de ejecutarlas el bot pregunta "¿Confirmas?".';
$string['limite_diario'] = 'Has llegado al límite diario de mensajes con el asistente. Inténtalo mañana.';
$string['tool_desconocida'] = 'Tool desconocida: {$a}';
$string['ws_error'] = 'Error de Moodle al ejecutar {$a}';
$string['deepseek_curl'] = 'Error de red con DeepSeek: {$a}';
$string['deepseek_http'] = 'DeepSeek respondió {$a}';
$string['deepseek_invalid'] = 'Respuesta inválida de DeepSeek: {$a}';
$string['bot_no_configurado'] = 'El asistente no está configurado: falta la API key de DeepSeek (Administración > Plugins > local_mcpchat).';
$string['demasiadas_ops'] = 'Demasiadas operaciones seguidas; pide el resto en otro mensaje.';
$string['respuesta_vacia'] = 'No he podido generar una respuesta; intenta reformular la petición.';
