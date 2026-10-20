# Bot de chat de gestión (local_mcpchat)

Chat dentro de Moodle donde **profesores y administradores** gestionan el
sitio conversando con el bot "Asistente IA" (bandeja de mensajería nativa;
cero UI propia → compatible con todos los temas). El bot usa la API de
DeepSeek (`deepseek-v4-flash`) con function calling y ejecuta las **mismas
34 tools** del servidor MCP, pero **en proceso** (external_api +
set_user): los permisos de Moodle son la barrera; el bot jamás usa admin ni
tokens de servicio.

## Arquitectura

- `local/mcpchat/` — plugin (sobrevive upgrades de Moodle).
- **Doble vía de procesamiento**:
  1. **Inmediata (navegador)**: al enviar un mensaje, el JS
     `local_mcpchat/trigger` (AMD) llama al WS ajax
     `local_mcpchat_trigger_turno` → respuesta en ~2-10 s (el drawer
     muestra el mensaje del bot en su poll de conversación abierta).
     Exclusión mutua con el cron vía el lock de adhoc tasks
     (`'adhoc_'.$taskid`, factory 'cron'); la task se borra antes de
     ejecutar para que un mensaje nuevo durante el turno encole otra
     (auto-curación).
  2. **Fallback (cron)**: si el JS no corre (o el WS falla), el evento
     `\core\event\message_sent` → adhoc task → cron → `bot.php` procesa el
     mensaje igualmente (60 s+). El bot deja de depender del cron solo si
     el navegador está abierto; los e2e en CLI usan la vía 1 directa.
- `bot.php`: contexto (últimos N mensajes) → DeepSeek (tools) → dispatcher
  (`call_external_function` con `set_user(remitente)`) → respuesta en la
  conversación. Máx 5 tool_calls por turno.
- **Formato de respuestas**: el bot envía con `FORMAT_HTML` (el drawer lo
  renderiza tras HTMLPurifier). La salida del modelo pasa por
  `bot::formatear_html()`: si trae markdown residual se convierte
  (`**`→`<b>`, listas, párrafos, `##`→`<h4>`, tablas `|a|b|`→`<table>`);
  las confirmaciones usan etiquetas en español (Tarea/Estudiante/Curso/
  Nota...), fechas legibles y frases de resultado humanas — nunca JSON
  crudo ni ids sin etiquetar (el prompt prohíbe columnas de ID).
- **Confirmación**: las tools riesgosas (configurables en settings) piden
  "¿Confirmas?" antes de ejecutar; el siguiente mensaje "sí" las ejecuta.
- **Auditoría**: tabla `local_mcpchat_audit` (quién, tool, params, resultado).
- **Límite**: mensajes/día por usuario (default 100).

## Instalar (local o Hostinger)

1. Copiar `local-mcpchat/` a `$MOODLE/local/mcpchat/` y correr
   `php admin/cli/upgrade.php --non-interactive` (crea tablas + usuario
   bot `asistente_ia`).
2. Administración > Plugins > local_mcpchat: poner la API key de DeepSeek.
3. (Opcional) Ajustar modelo, prompt del sistema, límite diario y lista de
   tools riesgosas.

## Despliegue en Hostinger

- **Hosting compartido (recomendado)**: PHP 8.3, sin root — el plugin es
  PHP puro y solo necesita salida HTTPS a `api.deepseek.com`.
- **Cron es el corazón del bot**: cada mensaje se procesa en la adhoc task
  que corre en el cron. Configurar un cron job que ejecute
  `php /home/USUARIO/dominio/public_html/admin/cli/cron.php` cada minuto
  (verificar el intervalo mínimo permitido por el plan contratado; si es
  cada 5 min, el bot responde en ≤5 min).
- **Privacidad de mensajería**: el bot se crea con "cualquiera puede
  escribirle". Si un profesor no puede iniciar el chat o no recibe
  respuestas, pedirle activar "Permitir que cualquier persona me escriba"
  en su perfil (o agregar al bot como contacto).
- **Costos**: ~$0.0004 por turno (el prompt del sistema + los 34 schemas
  se cachean a $0.0028/M). 5k mensajes/mes ≈ $2-3; 100k ≈ $15-25. El
  límite diario por usuario acota el peor caso.
- **El bot es autónomo**: no requiere el servidor MCP (ese queda solo para
  Claude Code en local).

## e2e (desarrollo, Docker local)

```bash
# Task 2 (cadena sin LLM)
docker compose exec -T web php /var/www/html/local/seedscripts/e2e/mcpchat/t2_cadena.php
# Task 3-6 (con API real; DEEPSEEK_API_KEY=sk-...)
DEEPSEEK_API_KEY=sk-... docker compose exec -T web php /var/www/html/local/seedscripts/e2e/mcpchat/t3_deepseek.php
DEEPSEEK_API_KEY=sk-... docker compose exec -T web php /var/www/html/local/seedscripts/e2e/mcpchat/t5_flow.php
# Task 6 (trigger inmediato sin cron + respuestas HTML)
DEEPSEEK_API_KEY=sk-... docker compose exec -T web php /var/www/html/local/seedscripts/e2e/mcpchat/t6_trigger.php
```

## Regenerar los schemas de tools

```bash
cd mcp-server && uv run python scripts/exportar_schemas.py   # -> local-mcpchat/json/tools.json
```

Los schemas (y solo los schemas) se exportan del servidor MCP: si cambian
tools en el MCP, regenerar el JSON y committearlo.
