"""Tools de contenido: creacion de actividades via el plugin local_mcpbridge.

El plugin PHP expone local_mcpbridge_create_activity (assign, page,
resource, url, label). Estas tools son su fachada MCP, con token docente.
"""


def register_contenido(mcp, get_client, to_json_text):
    @mcp.tool()
    def crear_tarea(curso_id: int, nombre: str, instrucciones: str = "",
                    fecha_limite_ts: int = 0, nota_maxima: int = 100) -> str:
        """⚠️ ESCRITURA. Crea una tarea (assign) en un curso.

        Args:
            fecha_limite_ts: timestamp Unix de la fecha limite (0 = sin fecha).
            nota_maxima: nota maxima de la tarea (default 100).
        """
        client = get_client(escritura=True)
        data = client.call("local_mcpbridge_create_activity",
                           courseid=curso_id, type="assign", name=nombre,
                           intro=instrucciones, duedate=fecha_limite_ts,
                           grade=nota_maxima)
        return to_json_text({"ok": True, "cmid": data.get("cmid"),
                             "instance": data.get("instance"),
                             "modname": data.get("modname")})

    @mcp.tool()
    def crear_pagina(curso_id: int, nombre: str,
                     instrucciones: str = "") -> str:
        """⚠️ ESCRITURA. Crea una pagina (page) en un curso.

        Nota: el contenido HTML de la pagina se edita luego en la UI; el WS
        solo fija el nombre y la descripcion (intro).
        """
        client = get_client(escritura=True)
        data = client.call("local_mcpbridge_create_activity",
                           courseid=curso_id, type="page", name=nombre,
                           intro=instrucciones)
        return to_json_text({"ok": True, "cmid": data.get("cmid"),
                             "instance": data.get("instance"),
                             "modname": data.get("modname")})

    @mcp.tool()
    def crear_recurso(curso_id: int, nombre: str,
                      contenido: str = "", instrucciones: str = "") -> str:
        """⚠️ ESCRITURA. Crea un archivo (resource) en un curso.

        Nota: el archivo real se adjunta luego en la UI; el contenido es
        solo texto de ejemplo (v1 del plugin).
        """
        client = get_client(escritura=True)
        data = client.call("local_mcpbridge_create_activity",
                           courseid=curso_id, type="resource", name=nombre,
                           intro=instrucciones, content=contenido)
        return to_json_text({"ok": True, "cmid": data.get("cmid"),
                             "instance": data.get("instance"),
                             "modname": data.get("modname")})

    @mcp.tool()
    def crear_enlace(curso_id: int, nombre: str, url: str) -> str:
        """⚠️ ESCRITURA. Crea un enlace (url) a una pagina web en un curso."""
        client = get_client(escritura=True)
        data = client.call("local_mcpbridge_create_activity",
                           courseid=curso_id, type="url", name=nombre,
                           intro="", content=url)
        return to_json_text({"ok": True, "cmid": data.get("cmid"),
                             "instance": data.get("instance"),
                             "modname": data.get("modname")})

    @mcp.tool()
    def crear_etiqueta(curso_id: int, texto: str) -> str:
        """⚠️ ESCRITURA. Crea una etiqueta (label) con texto en un curso."""
        client = get_client(escritura=True)
        data = client.call("local_mcpbridge_create_activity",
                           courseid=curso_id, type="label", name=texto,
                           intro="")
        return to_json_text({"ok": True, "cmid": data.get("cmid"),
                             "instance": data.get("instance"),
                             "modname": data.get("modname")})
