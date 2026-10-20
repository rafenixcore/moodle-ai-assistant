"""Tests del flujo compuesto: analizar_entregas_pendientes (solo lectura).

Los shapes de respuesta replican los reales de Moodle 5.2 verificados
via REST: submissions/grades envueltos en assignments[] y list_participants
como lista directa con flags submitted/requiregrading.
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
    """Registra las tools de flujos y captura el modo del cliente."""
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

    from moodle_mcp.tools.flujos import register_flujos
    register_flujos(FakeMCP(), get_client, to_json_text)
    return tools_registradas, modos


# --- Datos de ejemplo: 6 entregados (1 con nota, 5 sin nota) + 4 sin entregar.
PARTICIPANTES = [
    {"id": 3, "fullname": "Estudiante 01", "submitted": True,
     "requiregrading": False, "submissionstatus": "submitted"},
    {"id": 4, "fullname": "Estudiante 02", "submitted": True,
     "requiregrading": True, "submissionstatus": "submitted"},
    {"id": 5, "fullname": "Estudiante 03", "submitted": True,
     "requiregrading": True, "submissionstatus": "submitted"},
    {"id": 6, "fullname": "Estudiante 04", "submitted": True,
     "requiregrading": True, "submissionstatus": "submitted"},
    {"id": 7, "fullname": "Estudiante 05", "submitted": True,
     "requiregrading": True, "submissionstatus": "submitted"},
    {"id": 8, "fullname": "Estudiante 06", "submitted": True,
     "requiregrading": True, "submissionstatus": "submitted"},
    {"id": 9, "fullname": "Estudiante 07", "submitted": False,
     "requiregrading": False, "submissionstatus": "draft"},
    {"id": 10, "fullname": "Estudiante 08", "submitted": False,
     "requiregrading": False, "submissionstatus": "draft"},
    {"id": 11, "fullname": "Estudiante 09", "submitted": False,
     "requiregrading": False, "submissionstatus": "nothingtograde"},
    {"id": 12, "fullname": "Estudiante 10", "submitted": False,
     "requiregrading": False, "submissionstatus": "nothingtograde"},
]

SUBMISSIONS = {"assignments": [{"assignmentid": 1, "submissions": [
    {"id": 1, "userid": 3, "status": "submitted",
     "timemodified": 1786753832, "gradingstatus": "graded"},
    {"id": 2, "userid": 4, "status": "submitted",
     "timemodified": 1786753832, "gradingstatus": "notgraded"},
    {"id": 3, "userid": 5, "status": "submitted",
     "timemodified": 1786753832, "gradingstatus": "notgraded"},
    {"id": 4, "userid": 6, "status": "submitted",
     "timemodified": 1786753832, "gradingstatus": "notgraded"},
    {"id": 5, "userid": 7, "status": "submitted",
     "timemodified": 1786753832, "gradingstatus": "notgraded"},
    {"id": 6, "userid": 8, "status": "submitted",
     "timemodified": 1786753832, "gradingstatus": "notgraded"},
]}]}

GRADES = {"assignments": [{"assignmentid": 1, "grades": [
    {"userid": 3, "grade": "80.00000", "grader": 13},
]}], "warnings": []}


def respuestas():
    return {"mod_assign_get_submissions": SUBMISSIONS,
            "mod_assign_get_grades": GRADES,
            "mod_assign_list_participants": PARTICIPANTES}


def test_analizar_es_solo_lectura():
    client = FakeClient(respuestas())
    tools, modos = registrar(client)

    tools["analizar_entregas_pendientes"](asignacion_id=1)

    assert modos == [False], "el analisis nunca debe pedir el token docente"


def test_analizar_agrupa_entregados_no_entregados_y_sin_nota():
    client = FakeClient(respuestas())
    tools, _ = registrar(client)

    salida = tools["analizar_entregas_pendientes"](asignacion_id=1)
    informe = json.loads(salida)

    assert informe["asignacion_id"] == 1
    assert informe["total_estudiantes"] == 10
    assert informe["resumen"] == {"entregados": 6, "no_entregados": 4,
                                  "sin_nota": 5, "tardias": 0}
    # Entregados: nombre, nota y submission_id.
    e1 = informe["entregados"][0]
    assert e1 == {"user_id": 3, "nombre": "Estudiante 01",
                  "submission_id": 1, "nota": 80.0, "tardia": False}
    # Sin nota: los 5 entregados que no tienen entrada en get_grades.
    assert [s["user_id"] for s in informe["entregas_sin_nota"]] == [4, 5, 6, 7, 8]
    assert informe["entregas_sin_nota"][0]["tardia"] is False
    # No entregados: nombre y estado.
    assert informe["no_entregados"][0] == {"user_id": 9,
                                           "nombre": "Estudiante 07",
                                           "estado": "draft"}


def test_analizar_marca_tardias_con_fecha_limite():
    client = FakeClient(respuestas())
    tools, _ = registrar(client)

    # Solo el estudiante 3 entrego fuera de plazo (timemodified > limite).
    client.respuestas["mod_assign_get_submissions"] = {
        "assignments": [{"assignmentid": 1, "submissions": [
            {"id": 1, "userid": 3, "status": "submitted",
             "timemodified": 1787000000, "gradingstatus": "graded"},
            {"id": 2, "userid": 4, "status": "submitted",
             "timemodified": 1786500000, "gradingstatus": "notgraded"},
        ]}]}

    salida = tools["analizar_entregas_pendientes"](
        asignacion_id=1, fecha_limite_ts=1786667432)
    informe = json.loads(salida)

    assert informe["fecha_limite_ts"] == 1786667432
    tardios = [e for e in informe["entregados"] if e["tardia"]]
    assert [e["user_id"] for e in tardios] == [3]
    assert informe["resumen"]["tardias"] == 1


def test_analizar_usa_params_correctos():
    client = FakeClient(respuestas())
    tools, _ = registrar(client)

    tools["analizar_entregas_pendientes"](asignacion_id=1)

    assert client.llamadas == [
        ("mod_assign_get_submissions",
         {"assignmentids": [1], "status": "submitted"}),
        ("mod_assign_get_grades", {"assignmentids": [1]}),
        ("mod_assign_list_participants",
         {"assignid": 1, "groupid": 0, "filter": "", "skip": 0,
          "limit": 0, "onlyids": 1, "includeenrolments": 1}),
    ]


def test_resumir_entrega_devuelve_texto_via_plugin():
    # El texto del onlinetext no lo expone mod_assign_get_submissions (solo
    # fileareas): va por el WS del plugin local_mcpbridge (solo lectura).
    client = FakeClient({"local_mcpbridge_get_submission_text": {
        "submissionid": 1, "assignmentid": 1, "userid": 3,
        "status": "submitted", "onlinetext": "<p>Informe final...</p>"}})
    tools, modos = registrar(client)

    salida = tools["resumir_entrega"](submission_id=1)
    data = json.loads(salida)

    assert data["onlinetext"] == "<p>Informe final...</p>"
    assert client.llamadas == [
        ("local_mcpbridge_get_submission_text", {"submissionid": 1})]
    # Solo lectura: nunca pide el token docente.
    assert modos == [False]
