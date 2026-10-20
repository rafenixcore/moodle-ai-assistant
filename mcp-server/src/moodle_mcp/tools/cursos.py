"""Tools de lectura de cursos.

Nota Moodle 5.2: core_course_get_courses exige moodle/course:view (permiso
de sitio, solo admin), asi que con tokens de teacher no sirve. El camino
que si funciona: core_webservice_get_site_info (userid del token) +
core_enrol_get_users_courses (cursos propios del token).
"""


def register_cursos(mcp, get_client, to_json_text):
    @mcp.tool()
    def listar_cursos() -> str:
        """Lista los cursos visibles para el token actual (id, nombre corto, nombre)."""
        client = get_client()
        info = client.call("core_webservice_get_site_info")
        data = client.call("core_enrol_get_users_courses",
                           userid=info["userid"])
        return to_json_text([
            {"course_id": c["id"], "shortname": c["shortname"],
             "fullname": c["fullname"]}
            for c in data
        ])

    @mcp.tool()
    def listar_actividades(curso_id: int) -> str:
        """Lista las actividades de un curso con su tipo, id y seccion."""
        client = get_client()
        data = client.call("core_course_get_contents", courseid=curso_id)
        salida = []
        for section in data:
            for module in section.get("modules", []):
                salida.append({
                    "cmid": module["id"],
                    "tipo": module["modname"],
                    "nombre": module["name"],
                    "seccion": section.get("section"),
                    "visible": module.get("visible"),
                })
        return to_json_text(salida)
