"""Tests de la clonacion de cursos (backup/restore async via WS).

Firmas reales Moodle 5.2 verificadas en el contenedor:
- core_webservice_get_site_info devuelve el userid del token user
  (core_course_get_courses exige moodle/course:view y no sirve para
  teachers).
- core_enrol_get_users_courses(userid) devuelve los cursos propios como
  lista directa (no {campo: [...]}) e incluye startdate.
- core_backup_submit_copy_form es INUSABLE por WS stateless: el copy_form
  exige _qf__ + sesskey de sesion (missingparam). La copia se lanza con
  local_mcpbridge_copy_course (plugin PHP, mismo flujo que la UI).
- core_backup_get_copy_progress(copies=[{backupid, restoreid, operation}]).
"""
import json

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
    """Registra las tools de clonacion y captura el modo del cliente."""
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

    from moodle_mcp.tools.clonacion import register_clonacion
    register_clonacion(FakeMCP(), get_client, to_json_text)
    return tools_registradas, modos


def site_info():
    return {"userid": 14, "username": "ws_lectura"}


def curso_existe():
    return [{"id": 2, "fullname": "Pruebas MCP 2026",
             "shortname": "MCP2026", "startdate": 1700000000}]


def curso_no_existe():
    return []


def curso_sin_fecha():
    return [{"id": 2, "fullname": "Pruebas MCP 2026",
             "shortname": "MCP2026", "startdate": 0}]


def copia_ok():
    return {"backupid": "abc123", "restoreid": "def456", "courseid": 3}


def test_clonar_curso_valida_curso_origen():
    client = FakeClient({"core_webservice_get_site_info": site_info(),
                         "core_enrol_get_users_courses": curso_no_existe()})
    tools, modos = registrar(client)

    salida = tools["clonar_curso"](
        curso_origen_id=999, nombre_nuevo="Clon", shortname_nuevo="CLON1")

    assert '"ok": false' in salida
    # Si el curso no existe no se intenta copiar nada.
    assert client.llamadas == [
        ("core_webservice_get_site_info", {}),
        ("core_enrol_get_users_courses", {"userid": 14})]
    assert modos == [False]


def test_clonar_curso_llama_plugin_para_copiar():
    client = FakeClient({"core_webservice_get_site_info": site_info(),
                         "core_enrol_get_users_courses": curso_existe(),
                         "local_mcpbridge_copy_course": copia_ok()})
    tools, modos = registrar(client)

    salida = tools["clonar_curso"](
        curso_origen_id=2, nombre_nuevo="Clon MCP",
        shortname_nuevo="CLON-MCP")

    # La copia va por el plugin (core_backup_submit_copy_form es inusable:
    # formslib exige _qf__ + sesskey y el WS stateless no puede producirlo).
    wsfunction, params = client.llamadas[2]
    assert wsfunction == "local_mcpbridge_copy_course"
    assert params == {"courseid": 2, "fullname": "Clon MCP",
                      "shortname": "CLON-MCP", "category": 1, "visible": 1,
                      "startdate": 1700000000}

    # Devuelve los copyids y el id del curso nuevo.
    informe = json.loads(salida)
    assert informe["ok"] is True
    assert informe["copyids"] == {"backupid": "abc123",
                                  "restoreid": "def456"}
    assert informe["courseid_nuevo"] == 3
    assert modos == [False, True], "validar=lectura, copiar=escritura"


def test_clonar_curso_sin_fecha_envia_0():
    # startdate=0 (curso sin fecha): se envia 0 y el plugin aplica su
    # default (manana a medianoche) — el cliente no calcula fechas.
    client = FakeClient({"core_webservice_get_site_info": site_info(),
                         "core_enrol_get_users_courses": curso_sin_fecha(),
                         "local_mcpbridge_copy_course": copia_ok()})
    tools, modos = registrar(client)

    salida = tools["clonar_curso"](
        curso_origen_id=2, nombre_nuevo="Clon MCP",
        shortname_nuevo="CLON-MCP")

    wsfunction, params = client.llamadas[2]
    assert params["startdate"] == 0
    assert json.loads(salida)["ok"] is True


def test_estado_clonacion_usa_token_docente():
    # El WS valida el contexto del curso clonado (nuevo, sin matriculados)
    # y el job pertenece al docente: requiere el token de escritura.
    client = FakeClient({"core_backup_get_copy_progress": [
        {"backupid": "abc123", "status": 1, "progress": 50}]})
    tools, modos = registrar(client)

    salida = tools["estado_clonacion"](
        backupid="abc123", restoreid="def456", operation="copy")

    assert '"progress": 50' in salida
    assert client.llamadas == [
        ("core_backup_get_copy_progress",
         {"copies": [{"backupid": "abc123", "restoreid": "def456",
                      "operation": "copy"}]})]
    assert modos == [True]
