# Moodle AI Assistant

[![CI](https://github.com/hrafael2011/moodle-ai-assistant/actions/workflows/ci.yml/badge.svg)](https://github.com/hrafael2011/moodle-ai-assistant/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Asistente conversacional **dentro de Moodle** para docentes: 34 herramientas
sobre los Web Services REST de Moodle, con **confirmación humana** antes de
cualquier escritura y doble barrera de permisos (token de lectura vs. token de
docente).

El catálogo de herramientas se construyó y validó primero como **servidor MCP**
en Claude Code. Cuando estuvo probado, las mismas herramientas se reutilizaron
como **bot nativo** dentro de Moodle con function calling. Un catálogo, dos
superficies.

> IA nativa dentro de Moodle: `docs/ia_nativa.md` · Bot de chat: `docs/mcpchat.md`

## Arquitectura (4 capas)

```
Moodle en Docker (MariaDB + PHP 8.3/Apache + cron)
        │  Web Services REST (tokens permanentes por rol)
        ▼
Servidor MCP Python (SDK oficial mcp, stdio, tools registradas por modulo)
        │  MCP stdio (JSON-RPC)
        ▼
Cliente IA (Claude Code / MCP Inspector)
```

## Requisitos

- Docker + Docker Compose
- Python 3.12+ con `uv` (para el servidor MCP)
- Ollama en el host (opcional, solo IA nativa — ver `docs/ia_nativa.md`)

## Levantar el proyecto

```bash
cp .env.example .env            # rellenar contraseñas
docker compose up -d            # db + web (http://localhost:8080) + cron
docker compose exec web php admin/cli/install.php --wwwroot=http://localhost:8080 \
  --dataroot=/var/www/moodledata --dbtype=mariadb --dbhost=db --dbname=moodle \
  --dbuser=moodle --dbpass=cambiar_password --adminuser=admin \
  --adminpass=Admin123! --adminemail=admin@example.local \
  --fullname="Moodle MCP" --shortname=moodle --non-interactive --agree-license
docker compose exec web php local/seedscripts/cli/seed.php          # datos de prueba
docker compose exec web php local/seedscripts/cli/ws_config.php     # servicio + tokens
```

`ws_config.php` imprime al final los tokens: **copiarlos a `.env`**
(`MOODLE_TOKEN_DOCENTE`, `MOODLE_TOKEN_LECTURA`). Es idempotente; re-ejecutarlo
no duplica usuarios ni tokens.

### Roles de los tokens

| Token | Usuario WS | Rol en el curso | Puede |
|---|---|---|---|
| `MOODLE_TOKEN_LECTURA` | `ws_lectura` | teacher (no editor) | leer todo, ver notas |
| `MOODLE_TOKEN_DOCENTE` | `ws_docente` | editingteacher | escribir y calificar |

Ambos tienen además el rol `webservice` en sistema (solo `webservice/rest:use`).
El servicio `asignaciones_mcp` permite una lista blanca de funciones WS.

`ws_config.php` matricula a ambos usuarios WS en **todos** los cursos reales
(id > 1): los cursos creados con `crear_curso` no incluyen a los tokens
(chicken-and-egg de capacidad), así que sin esa matriculación las tools de
escritura fallarían con "Curso o actividad no accesible".

## Conectar Claude Code

`.mcp.json` (raíz del repo) declara el servidor `moodle`. El bloque `env`
pasa las variables del `.env` al proceso del servidor (el SDK de mcp **no**
hereda variables de entorno: hay que declararlas explícitamente):

```json
{
  "mcpServers": {
    "moodle": {
      "command": "uv",
      "args": ["run", "--project", "mcp-server", "moodle-mcp"],
      "env": {
        "MOODLE_URL": "http://localhost:8080",
        "MOODLE_TOKEN_LECTURA": "${MOODLE_TOKEN_LECTURA}",
        "MOODLE_TOKEN_DOCENTE": "${MOODLE_TOKEN_DOCENTE}"
      }
    }
  }
}
```

Con el **MCP Inspector** hay que teclear el mismo bloque `env` en la UI
(no hay herencia de entorno). Verificar la conexión con `listar_cursos`.

## Bot de chat de gestión (local_mcpchat)

Chat dentro de Moodle para profesores y administradores: el bot "Asistente
IA" (bandeja de mensajería nativa) ejecuta las mismas 34 tools con la API
de DeepSeek y los permisos del propio usuario. Plugin autónomo (no usa el
servidor MCP). Configuración, despliegue en Hostinger y e2e:
`docs/mcpchat.md`.

## Catálogo de tools (34)

Lectura = solo consulta. **Escritura** = ⚠️ y confirmación humana en el
cliente.

### Cursos y contenido
| Tool | Riesgo | Qué hace |
|---|---|---|
| `listar_cursos` | lectura | cursos del token (site_info + get_users_courses) |
| `crear_curso` | ⚠️ escritura | crea un curso nuevo (nombre, shortname, descripción) |
| `listar_actividades` | lectura | actividades de un curso (tipo, id, sección) |
| `crear_tarea` / `crear_pagina` / `crear_recurso` / `crear_enlace` / `crear_etiqueta` | ⚠️ escritura | crea actividades (plugin local_mcpbridge) |
| `clonar_curso` | ⚠️ escritura | clona un curso (asíncrono, job en cron) |
| `estado_clonacion` | lectura | progreso del job de clonación |

### Asignaciones y calificación
| Tool | Riesgo | Qué hace |
|---|---|---|
| `listar_asignaciones` / `tareas_urgentes` | lectura | tareas y fechas límite (urgentes primero) |
| `listar_entregas` / `estado_estudiante` | lectura | entregas de una tarea / de un estudiante |
| `analizar_entregas_pendientes` | lectura | informe: quién entregó, quién no, sin nota, tardías |
| `resumir_entrega` | lectura | texto de una entrega (para que la IA del cliente la resuma) |
| `calificar_entrega` | ⚠️ escritura **irreversible** | guarda nota + feedback (comentarios) |
| `calificar_lote` | ⚠️ escritura en lote | varias calificaciones en una llamada |
| `bloquear_entregas` / `desbloquear_entregas` | ⚠️ escritura | impide / permite modificar la entrega |
| `otorgar_prorroga` | ⚠️ escritura | extiende la fecha límite de un estudiante |
| `revertir_a_borrador` | ⚠️ escritura | devuelve la entrega a borrador |

### Usuarios, matriculación y organización
| Tool | Riesgo | Qué hace |
|---|---|---|
| `buscar_usuario` | lectura | por username, email o nombre (con fallback) |
| `crear_usuario` | ⚠️ escritura | crea usuario (requiere token de admin) |
| `matricular_usuario` / `desmatricular_usuario` | ⚠️ escritura | matricula/desmatricula (rol: student/teacher) |
| `crear_grupo` / `agregar_a_grupo` | ⚠️ escritura | grupos de curso |

### Comunicación y calendario
| Tool | Riesgo | Qué hace |
|---|---|---|
| `listar_foros` / `listar_discusiones` | lectura | foros de un curso / discusiones de un foro |
| `publicar_anuncio` / `responder_discusion` | ⚠️ escritura | publica / responde (HTML) |
| `enviar_mensaje` | ⚠️ escritura | mensaje a una conversación existente |
| `crear_evento` | ⚠️ escritura | evento de calendario (curso o sitio) |

### IA nativa (dentro de Moodle)
Provider `Ollama local (DeepSeek R1)` configurado vía `scripts/cli/ai_config.php`
(modelo `deepseek-r1:1.5b`). Se usa desde el editor TinyMCE y el Course
Assistant; el servidor MCP no llama a esa IA (ver `docs/ia_nativa.md`).

## Rotar tokens

El script `ws_config.php` crea tokens si no existen, pero **no** los rota.
Para rotar (p. ej. ante fuga): borrar los registros y re-ejecutar

```bash
docker compose exec -T web sh -c 'php /dev/stdin' << 'PHP'
<?php
define("CLI_SCRIPT", true);
require_once("/var/www/html/public/config.php");
global $DB;
$DB->delete_records("external_tokens", array(
    "externalserviceid" => $DB->get_field("external_services", "id",
        array("shortname" => "asignaciones_mcp")), "tokentype" => 0));
PHP
docker compose exec web php local/seedscripts/cli/ws_config.php   # imprime los nuevos
```

Actualizar `.env` con los nuevos valores y reiniciar el servidor MCP.

## Actualizar Moodle sin romper el plugin

- El codebase se monta en `/var/www/html` (webroot: `/var/www/html/public`).
  El plugin del proyecto vive en `local-mcpbridge/` → montado en
  `/var/www/html/public/local/mcpbridge`: **sobrevive a actualizaciones** de
  Moodle porque no toca core (`local/` es el lugar reservado para eso).
- Los scripts propios (`scripts/`) → `/var/www/html/local/seedscripts`.
- Tras actualizar el codebase: `php admin/cli/upgrade.php --non-interactive`
  y, si faltan tablas del `install.xml` nuevo, sincronizar el esquema (ver
  Task 20: las tablas `ai_*` no se crean si la versión no cambia).
- Re-ejecutar `ws_config.php` para religar funciones al servicio y revisar
  que los WS del plugin siguen listados (purge de caches tras cambios en
  `db/services.php`).

## Tests y desarrollo

```bash
cd mcp-server && uv run pytest tests/ -v      # 55 tests con cliente falso
```

Verificación real contra el Moodle vivo: scripts `verify_mcp_t*.py` que
lanzan el servidor por stdio con el bloque `env` explícito. Convenciones:
el pin `mcp>=1.2.0,<2` (la v2 del SDK cambió la API), `get_client`/
`to_json_text` inyectados en cada registro de tools, docstrings con el
hallazgo de Moodle 5.2 que motivó cada decisión (varios WS del core son
inusables con tokens de teacher: `core_course_get_courses`,
`core_backup_submit_copy_form`…).

## Estructura

```
moodle-latest-502/   codebase de Moodle 5.2 (montado en /var/www/html)
docker/              Dockerfile web (PHP 8.3 + Apache) y config
scripts/cli/         seed.php, ws_config.php (tokens), ai_config.php (IA)
local-mcpbridge/     plugin local_mcpbridge (WS propios, sobrevive upgrades)
mcp-server/          servidor MCP Python (src/moodle_mcp/tools/*)
docs/                plan, manual de prompts, IA nativa
```
