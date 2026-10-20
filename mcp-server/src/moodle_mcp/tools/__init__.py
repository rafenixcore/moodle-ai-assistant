"""Registro de tools por dominio.

Cada modulo expone register_*(mcp, get_client, to_json_text) y el paquete
las re-exporta para que server.py llame a tools.register_* directamente.
"""
from moodle_mcp.tools.asignaciones import register_asignaciones
from moodle_mcp.tools.clonacion import register_clonacion
from moodle_mcp.tools.comunicacion import register_comunicacion
from moodle_mcp.tools.contenido import register_contenido
from moodle_mcp.tools.cursos import register_cursos
from moodle_mcp.tools.flujos import register_flujos
from moodle_mcp.tools.gestion import register_gestion

__all__ = ["register_asignaciones", "register_clonacion",
           "register_comunicacion", "register_contenido", "register_cursos",
           "register_flujos", "register_gestion"]
