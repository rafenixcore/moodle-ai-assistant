# IA nativa de Moodle: dos caminos, sin duplicar lógica (YAGNI)

Task 20 del plan. El servidor MCP **no** llama a Ollama ni a ningún proveedor
de IA directamente: la lógica de generación de texto vive en el cliente
(Claude) o dentro de Moodle. Esto mantiene al servidor MCP como un puente
puro a los Web Services.

## Camino A — La IA del cliente escribe el feedback (recomendado)

Flujo del proyecto:

1. `analizar_entregas_pendientes(asignacion_id)` — informe de quién entregó,
   quién no y qué entregas no tienen nota (solo lectura).
2. `resumir_entrega(submission_id)` — extrae el texto de la entrega
   (onlinetext) para que el cliente lo lea (solo lectura). El texto no lo
   expone `mod_assign_get_submissions` (solo fileareas de archivos), así que
   va por el WS del plugin: `local_mcpbridge_get_submission_text`.
3. El cliente (Claude) genera el resumen/feedback con su propia IA y lo
   guarda con `calificar_entrega(nota, comentario)` — el comentario se
   guarda en el plugin de comentarios del feedback (assignfeedback_comments).

La guía de prompts de la Fase 5 no se publica con el código.

## Camino B — Proveedor nativo de Moodle (Ollama/DeepSeek local)

Moodle 5.2 trae el AI subsystem (`core_ai`) con providers en core
(`ai/provider/ollama`, `ai/provider/deepseek`, `openai`, …). Se usa para
acciones de IA **dentro** de Moodle: generación de texto en el editor
(TinyMCE, placement `aiplacement_editor`), Course Assistant, resúmenes de
curso. El servidor MCP no lo toca.

### Configuración (reproducible, `scripts/cli/ai_config.php`)

1. **Ollama en el host** escuchando en `127.0.0.1:11434` (default). No se
   toca el servicio del host: el compose añade el relay `ollama-proxy`
   (socat, `network_mode: host`) que expone `172.17.0.1:11434` (solo el
   bridge docker, nada a la LAN) y reenvía al loopback del host. El contenedor
   web llega al host por `host.docker.internal` (extra_hosts host-gateway).
2. **Seguridad cURL del sitio** (protección SSRF de Moodle): el default
   bloquea IPs privadas y puertos no estándar. Ajustado a:
   - `curlsecurityblockedhosts`: se quitó `172.16.0.0/12` (el bridge docker);
     se conserva loopback, LAN 192.168/10, 10/8 y cloud metadata.
   - `curlsecurityallowedport`: `443, 80, 11434`.
3. **`php local/seedscripts/cli/ai_config.php`** (idempotente):
   - crea la instancia de provider `aiprovider_ollama` en `mdl_ai_providers`
     con endpoint `http://host.docker.internal:11434`;
   - habilita 3 acciones con el modelo local: `generate_text`,
     `summarise_text`, `explain_text` (modelo `deepseek-r1:1.5b` o el que
     tenga Ollama: ver `curl localhost:11434/api/tags`);
   - lanza una llamada real de `process_action()` como prueba.

### Cómo se ve en la UI

- `Admin > IA > Proveedores de IA`: instancia "Ollama local (DeepSeek R1)".
- El editor TinyMCE muestra las herramientas de IA ("Generar texto",
  "Resumir", "Explicar") si el curso tiene activas las herramientas de IA
  (`enableaitools` en la configuración del curso).
- Course Assistant (`aiplacement_courseassist`) usa el mismo provider.
- La política de uso de IA puede requerir aceptación por usuario
  (`ai/policy_acceptance`); el admin la acepta en la UI al usarla.

### Notas de implementación (Moodle 5.2, verificadas)

- `core_ai\manager::create_provider_instance(classname, name, enabled, config, actionconfig)`
  — la columna de `ai_providers` se llama `provider` (get_class), **no**
  `classname`.
- El provider lee `actionconfig[action]['settings']['model']` y decodifica
  `modelextraparams` (JSON) como opciones extra de Ollama; requiere
  `settings['systeminstruction']` como string (null rompe el tipo).
- El endpoint del provider se compone como `endpoint + /api/generate`.
- `process_action()` resuelve el provider habilitado para la acción y guarda
  el resultado en `ai_action_results` (historial de uso).
- Modelos probados: `deepseek-r1:1.5b` (respondió en vivo), `tinyllama`.
  Modelos de razonamiento pueden tardar más del timeout del cliente HTTP.
