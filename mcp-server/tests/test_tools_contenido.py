"""Tests de las tools de contenido: crear actividades via local_mcpbridge."""
from moodle_mcp.server import to_json_text


class FakeClient:
    """MoodleClient falso: devuelve respuestas preprogramadas por funcion."""
    def __init__(self, respuestas):
        self.respuestas = respuestas
        self.llamadas = []

    def call(self, wsfunction, **params):
        self.llamadas.append((wsfunction, params))
        return self.respuestas[wsfunction]


def registrar(client):
    """Registra las tools de contenido y captura con que modo se llaman."""
    tools_registradas = {}
    modos_escritura = []

    class FakeMCP:
        def tool(self):
            def deco(fn):
                tools_registradas[fn.__name__] = fn
                return fn
            return deco

    def get_client(escritura=False):
        modos_escritura.append(escritura)
        return client

    from moodle_mcp.tools.contenido import register_contenido
    register_contenido(FakeMCP(), get_client, to_json_text)
    return tools_registradas, modos_escritura


def test_crear_tarea_es_escritura():
    client = FakeClient({"local_mcpbridge_create_activity": {
        "cmid": 9, "instance": 5, "modname": "assign"}})
    tools, modos = registrar(client)

    salida = tools["crear_tarea"](
        curso_id=2, nombre="Tarea MCP", instrucciones="Entregar informe",
        fecha_limite_ts=1789000000, nota_maxima=100)

    assert '"cmid": 9' in salida
    wsfunction, params = client.llamadas[0]
    assert wsfunction == "local_mcpbridge_create_activity"
    assert params == {"courseid": 2, "type": "assign", "name": "Tarea MCP",
                      "intro": "Entregar informe", "duedate": 1789000000,
                      "grade": 100}
    assert modos == [True]


def test_crear_tarea_usa_defaults():
    client = FakeClient({"local_mcpbridge_create_activity": {
        "cmid": 9, "instance": 5, "modname": "assign"}})
    tools, _ = registrar(client)

    tools["crear_tarea"](curso_id=2, nombre="Tarea sin fecha")

    _, params = client.llamadas[0]
    assert params == {"courseid": 2, "type": "assign",
                      "name": "Tarea sin fecha", "intro": "",
                      "duedate": 0, "grade": 100}


def test_crear_pagina_es_escritura():
    client = FakeClient({"local_mcpbridge_create_activity": {
        "cmid": 12, "instance": 1, "modname": "page"}})
    tools, modos = registrar(client)

    salida = tools["crear_pagina"](curso_id=2, nombre="Bienvenida",
                                   instrucciones="Lee esto")

    assert '"modname": "page"' in salida
    _, params = client.llamadas[0]
    assert params == {"courseid": 2, "type": "page",
                      "name": "Bienvenida", "intro": "Lee esto"}
    assert modos == [True]


def test_crear_recurso_es_escritura():
    client = FakeClient({"local_mcpbridge_create_activity": {
        "cmid": 13, "instance": 1, "modname": "resource"}})
    tools, modos = registrar(client)

    tools["crear_recurso"](curso_id=2, nombre="Lectura",
                           contenido="Texto del recurso")

    _, params = client.llamadas[0]
    assert params == {"courseid": 2, "type": "resource",
                      "name": "Lectura", "intro": "",
                      "content": "Texto del recurso"}
    assert modos == [True]


def test_crear_enlace_es_escritura():
    client = FakeClient({"local_mcpbridge_create_activity": {
        "cmid": 10, "instance": 1, "modname": "url"}})
    tools, modos = registrar(client)

    salida = tools["crear_enlace"](curso_id=2, nombre="Docs Moodle",
                                   url="https://docs.moodle.org")

    assert '"cmid": 10' in salida
    _, params = client.llamadas[0]
    assert params == {"courseid": 2, "type": "url",
                      "name": "Docs Moodle", "intro": "",
                      "content": "https://docs.moodle.org"}
    assert modos == [True]


def test_crear_etiqueta_es_escritura():
    client = FakeClient({"local_mcpbridge_create_activity": {
        "cmid": 11, "instance": 1, "modname": "label"}})
    tools, modos = registrar(client)

    salida = tools["crear_etiqueta"](curso_id=2, texto="Importante: parcial")

    assert '"cmid": 11' in salida
    _, params = client.llamadas[0]
    assert params == {"courseid": 2, "type": "label",
                      "name": "Importante: parcial", "intro": ""}
    assert modos == [True]
