"""Tests de las tools de lectura de asignaciones con cliente falso."""
import pytest

from moodle_mcp.server import to_json_text


class FakeClient:
    """MoodleClient falso: devuelve respuestas preprogramadas por funcion."""
    def __init__(self, respuestas):
        self.respuestas = respuestas
        self.llamadas = []

    def call(self, wsfunction, **params):
        self.llamadas.append((wsfunction, params))
        return self.respuestas[wsfunction]


def registrar(mcp_fake, client):
    """Registra las tools de asignaciones sobre un FastMCP falso."""
    tools_registradas = {}

    class FakeMCP:
        def tool(self):
            def deco(fn):
                tools_registradas[fn.__name__] = fn
                return fn
            return deco

    from moodle_mcp.tools.asignaciones import register_asignaciones
    register_asignaciones(FakeMCP(), lambda: client, to_json_text)
    return tools_registradas


COURSES_PAYLOAD = {
    "courses": [{
        "id": 7, "fullname": "Pruebas MCP 2026",
        "assignments": [
            {"id": 101, "name": "Informe inicial", "duedate": 1700000000},
            {"id": 102, "name": "Proyecto parcial", "duedate": 0},
        ],
        "grades": [],
    }],
}


def test_listar_asignaciones_resume_campos():
    client = FakeClient({"mod_assign_get_assignments": COURSES_PAYLOAD})
    tools = registrar(None, client)

    salida = tools["listar_asignaciones"]()

    assert "Informe inicial" in salida
    assert "assignment_id" in salida
    assert client.llamadas[0][0] == "mod_assign_get_assignments"


def test_listar_asignaciones_filtra_por_curso():
    client = FakeClient({"mod_assign_get_assignments": COURSES_PAYLOAD})
    tools = registrar(None, client)

    tools["listar_asignaciones"](curso_id=7)

    wsfunction, params = client.llamadas[0]
    assert params == {"courseids[0]": 7}


def test_tareas_urgentes_omite_sin_fecha_y_ordena():
    client = FakeClient({"mod_assign_get_assignments": COURSES_PAYLOAD})
    tools = registrar(None, client)

    salida = tools["tareas_urgentes"]()

    assert "Informe inicial" in salida       # con fecha
    assert "Proyecto parcial" not in salida  # duedate = 0, sin fecha
    assert "VENCIDA" in salida               # fecha 1700000000 ya paso


def test_listar_asignaciones_toma_nota_maxima_del_campo_grade():
    """La nota maxima viene en assignment.grade, no en course.grades."""
    payload = {
        "courses": [{
            "id": 7, "fullname": "Pruebas MCP 2026",
            "assignments": [
                {"id": 101, "name": "Informe inicial",
                 "duedate": 1700000000, "grade": 70},
            ],
        }],
    }
    client = FakeClient({"mod_assign_get_assignments": payload})
    tools = registrar(None, client)

    salida = tools["listar_asignaciones"]()

    assert '"nota_maxima": 70' in salida
    assert "maxgrade" not in salida
