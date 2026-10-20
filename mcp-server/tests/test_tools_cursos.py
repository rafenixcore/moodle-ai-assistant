"""Tests de los tools de cursos.

Moodle 5.2: core_course_get_courses exige moodle/course:view (solo admin),
asi que listar_cursos usa core_webservice_get_site_info (userid del token)
+ core_enrol_get_users_courses (cursos propios, lista directa).
"""
from moodle_mcp.server import to_json_text


class FakeClient:
    def __init__(self, respuestas):
        self.respuestas = respuestas
        self.llamadas = []

    def call(self, wsfunction, **params):
        self.llamadas.append((wsfunction, params))
        return self.respuestas[wsfunction]


def registrar(client):
    tools_registradas = {}
    modos = []

    class FakeMCP:
        def tool(self):
            def deco(fn):
                tools_registradas[fn.__name__] = fn
                return fn
            return deco

    def get_client(escritura=False):
        modos.append(escritura)
        return client

    from moodle_mcp.tools.cursos import register_cursos
    register_cursos(FakeMCP(), get_client, to_json_text)
    return tools_registradas, modos


def test_listar_cursos_usa_cursos_del_token_user():
    client = FakeClient({
        "core_webservice_get_site_info": {"userid": 14},
        "core_enrol_get_users_courses": [
            {"id": 2, "shortname": "MCP2026",
             "fullname": "Pruebas MCP 2026"},
            {"id": 5, "shortname": "OTRO",
             "fullname": "Otro curso"}],
    })
    tools, modos = registrar(client)

    salida = tools["listar_cursos"]()

    assert client.llamadas == [
        ("core_webservice_get_site_info", {}),
        ("core_enrol_get_users_courses", {"userid": 14})]
    cursos = json_loads(salida)
    assert cursos[0] == {"course_id": 2, "shortname": "MCP2026",
                         "fullname": "Pruebas MCP 2026"}
    assert cursos[1]["course_id"] == 5
    assert modos == [False]


def test_listar_actividades_usa_get_contents():
    client = FakeClient({"core_course_get_contents": [
        {"section": 1, "modules": [
            {"id": 10, "modname": "assign", "name": "A1",
             "visible": 1}]},
        {"section": 2, "modules": [
            {"id": 11, "modname": "forum", "name": "Foro",
             "visible": 0}]},
    ]})
    tools, modos = registrar(client)

    salida = tools["listar_actividades"](curso_id=2)

    assert client.llamadas == [("core_course_get_contents", {"courseid": 2})]
    act = json_loads(salida)
    assert act[0] == {"cmid": 10, "tipo": "assign", "nombre": "A1",
                      "seccion": 1, "visible": 1}
    assert act[1]["visible"] == 0
    assert modos == [False]


def json_loads(texto):
    import json
    return json.loads(texto)
