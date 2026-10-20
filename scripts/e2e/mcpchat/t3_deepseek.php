<?php
// e2e Task 3: cliente DeepSeek real (una llamada sin tools).
// Uso: DEEPSEEK_API_KEY=sk-... docker compose exec -T web php \
//      /var/www/html/local/seedscripts/e2e/mcpchat/t3_deepseek.php
define('CLI_SCRIPT', true);
require_once('/var/www/html/public/config.php');

$key = getenv('DEEPSEEK_API_KEY');
if (!$key) {
    fwrite(STDERR, "FALTA DEEPSEEK_API_KEY\n");
    exit(1);
}
$client = new \local_mcpchat\deepseek();
$resp = $client->chat(
    array(
        array('role' => 'system', 'content' => 'Responde con una sola palabra.'),
        array('role' => 'user', 'content' => 'Di hola'),
    ),
    array(),
    $key,
    get_config('local_mcpchat', 'model') ?: 'deepseek-v4-flash');

$texto = trim((string) ($resp['content'] ?? ''));
echo "RESPUESTA: $texto\n";
if ($texto === '' || $resp['tool_calls']) {
    fwrite(STDERR, "FAIL: respuesta vacia o tool_call inesperado\n");
    exit(1);
}
echo "PASS: DeepSeek responde OK\n";
