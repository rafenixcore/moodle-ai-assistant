"""Punto de ensamblaje: FastMCP + registro de todos los dominios de tools."""
import json
import sys

from mcp.server.fastmcp import FastMCP

from moodle_mcp import tools
from moodle_mcp.client import MoodleClient
from moodle_mcp.config import ConfigError, get_config

mcp = FastMCP("moodle")


def get_client(escritura: bool = False) -> MoodleClient:
    """Crea el cliente REST con el token apropiado al modo.

    lectura → MOODLE_TOKEN_LECTURA (seguro); escritura → MOODLE_TOKEN_DOCENTE
    (barrera de doble token: las tools de escritura piden explicitamente el
    token docente y fallan con un error claro si no esta configurado).
    Patched en tests para no usar red real.
    """
    cfg = get_config()
    if escritura:
        if not cfg["token_write"]:
            raise ConfigError(
                "MOODLE_TOKEN_DOCENTE no configurado: las tools de escritura "
                "requieren el token docente."
            )
        return MoodleClient(url=cfg["url"], token=cfg["token_write"])
    return MoodleClient(url=cfg["url"], token=cfg["token"])


def to_json_text(value) -> str:
    """Serializa estructuras a texto JSON para devolver al LLM."""
    return json.dumps(value, ensure_ascii=False, default=str, indent=2)


def register_all():
    """Registra los tools de todos los dominios en la instancia FastMCP."""
    tools.register_asignaciones(mcp, get_client, to_json_text)
    tools.register_clonacion(mcp, get_client, to_json_text)
    tools.register_comunicacion(mcp, get_client, to_json_text)
    tools.register_contenido(mcp, get_client, to_json_text)
    tools.register_cursos(mcp, get_client, to_json_text)
    tools.register_flujos(mcp, get_client, to_json_text)
    tools.register_gestion(mcp, get_client, to_json_text)
    return mcp
