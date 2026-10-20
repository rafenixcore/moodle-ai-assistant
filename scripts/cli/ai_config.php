<?php
// Configura la IA nativa de Moodle (AI subsystem) con el proveedor local
// Ollama del host. Idempotente: re-ejecutable.
//
// Equivale al flujo de la UI (Admin > IA > Proveedores de IA) hecho por CLI:
//   1. crear instancia de provider aiprovider_ollama (tabla ai_providers)
//   2. actionconfig: por cada accion (generate_text/summarise_text/explain_text)
//      habilitada con el modelo local (deepseek-r1:1.5b)
//   3. prueba real: process_action() de generate_text
//
// Requisitos de entorno (ver docs/ia_nativa.md):
//   - Ollama corriendo en el host (localhost:11434)
//   - Relay docker compose "ollama-proxy" (bind 172.17.0.1:11434) porque
//     Ollama escucha solo en loopback y el contenedor llega al host por el
//     bridge docker; el endpoint del provider es host.docker.internal:11434.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

\core\session\manager::set_user(get_admin());

$manager = \core\di::get(\core_ai\manager::class);
// Sin backslash inicial: to_record() guarda get_class() = 'aiprovider_ollama\provider'
// y el filtro de get_provider_records compara cadenas exactas.
$providerclass = 'aiprovider_ollama\provider';
$endpoint = 'http://host.docker.internal:11434';
$model = 'deepseek-r1:1.5b'; // modelo Ollama del host (deepseek-r1:1.5b o tinyllama)

$actions = array(
    \core_ai\aiactions\generate_text::class,
    \core_ai\aiactions\summarise_text::class,
    \core_ai\aiactions\explain_text::class,
);

// --- 1. Instancia de provider (buscar existente, crear si no) ---
// Ojo: la columna de ai_providers se llama 'provider' (get_class del provider),
// no 'classname'.
$providers = $manager->get_provider_records(array('provider' => $providerclass));
$provider = reset($providers);
if ($provider) {
    echo "Provider existente: id={$provider->id} ({$provider->name})\n";
    $instance = $manager->get_provider_instances(array('id' => $provider->id));
    $instance = reset($instance);
} else {
    $instance = $manager->create_provider_instance(
        classname: $providerclass,
        name: 'Ollama local (DeepSeek R1)',
        enabled: true,
        config: array('endpoint' => $endpoint),
    );
    echo "Provider creado: id={$instance->id} ({$instance->get_name()})\n";
}

// --- 2. Config por accion (actionconfig JSON en ai_providers) ---
$actionconfig = array();
foreach ($actions as $actionclass) {
    // Mismos campos que guarda configure_actions.php de la UI: el provider
    // lee actionconfig[action]['settings']['model'] para la llamada a Ollama
    // y decodifica 'modelextraparams' (JSON) como params extra del modelo.
    $actionconfig[$actionclass] = array(
        'enabled' => true,
        'settings' => array(
            'model' => $model,
            // El processor lee actionconfig[action]['settings']['systeminstruction']
            // (devuelve string; null rompe el tipo declarado).
            'systeminstruction' => '',
            'modelextraparams' => json_encode(array('temperature' => 0.7)),
        ),
        'modelsettings' => array('custom' => array('modelextraparams' => '{"temperature":0.7}')),
    );
}

$instance = $manager->update_provider_instance(
    provider: $instance,
    config: array('endpoint' => $endpoint),
    actionconfig: $actionconfig,
);
echo "Actionconfig aplicado: " . count($actionconfig) . " acciones habilitadas con modelo {$model}.\n";

// --- 3. Prueba real del provider (llamada a Ollama) ---
$syscontext = \context_system::instance();
$admin = get_admin();
$action = new \core_ai\aiactions\generate_text(
    $syscontext->id,
    $admin->id,
    'En una frase, que es un Learning Management System (LMS)?',
);
$response = $manager->process_action($action);

if ($response->get_success()) {
    $data = $response->get_response_data();
    echo "PRUEBA OK: " . substr($data['generatedcontent'] ?? '(sin contenido)', 0, 300) . "\n";
} else {
    echo "PRUEBA FALLIDA: error={$response->get_error()} ({$response->get_errorcode()})\n";
    echo "detalle: {$response->get_errormessage()}\n";
    exit(1);
}
