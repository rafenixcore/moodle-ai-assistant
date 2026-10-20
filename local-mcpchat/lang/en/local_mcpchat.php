<?php
// This file is part of the local_mcpchat plugin for Moodle.
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI management assistant (local_mcpchat)';
$string['apikey'] = 'DeepSeek API key';
$string['apikey_desc'] = 'DeepSeek API key (https://platform.deepseek.com). Used by the bot for tool-calling chat.';
$string['model'] = 'Model';
$string['model_desc'] = 'DeepSeek model (default: deepseek-v4-flash).';
$string['systemprompt'] = 'System prompt';
$string['systemprompt_desc'] = 'Bot behaviour instructions.';
$string['maxmsgsday'] = 'Max messages per user per day';
$string['maxmsgsday_desc'] = 'Anti-abuse/cost limit per user (0 = unlimited).';
$string['contextomsgs'] = 'Context messages';
$string['contextomsgs_desc'] = 'How many recent conversation messages are sent to the model.';
$string['riesgosas'] = 'Tools requiring confirmation';
$string['riesgosas_desc'] = 'Comma-separated list. Before running them the bot asks "Confirm?".';
$string['limite_diario'] = 'You have reached the daily message limit with the assistant. Try again tomorrow.';
$string['tool_desconocida'] = 'Unknown tool: {$a}';
$string['ws_error'] = 'Moodle error running {$a}';
$string['deepseek_curl'] = 'Network error with DeepSeek: {$a}';
$string['deepseek_http'] = 'DeepSeek responded {$a}';
$string['deepseek_invalid'] = 'Invalid DeepSeek response: {$a}';
$string['bot_no_configurado'] = 'The assistant is not configured: DeepSeek API key missing (Administration > Plugins > local_mcpchat).';
$string['demasiadas_ops'] = 'Too many consecutive operations; ask the rest in another message.';
$string['respuesta_vacia'] = 'I could not generate a response; try rephrasing your request.';
