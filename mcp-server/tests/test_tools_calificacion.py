"""Tests de las tools de escritura de calificacion (Fase 3, token docente)."""
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


def registrar(client):
    """Registra las tools de asignaciones y captura con que cliente se llaman."""
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

    from moodle_mcp.tools.asignaciones import register_asignaciones
    register_asignaciones(FakeMCP(), get_client, to_json_text)
    return tools_registradas, modos_escritura


def test_calificar_entrega_envia_save_grade():
    client = FakeClient({"mod_assign_save_grade": {}})
    tools, modos = registrar(client)

    tools["calificar_entrega"](
        asignacion_id=101, estudiante_id=21,
        nota=75.5, comentario="Buen trabajo")

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "mod_assign_save_grade"
    assert params == {
        "assignmentid": 101, "userid": 21, "grade": "75.5",
        "attemptnumber": -1,
        "addattempt": 0,
        "workflowstate": "released",
        "applytoall": False,
        "advancedgradingdata": {},
        # el feedback va DENTRO de plugindata (REST lo exige anidado);
        # top-level daria "Unexpected keys" en la validacion de Moodle.
        "plugindata": {
            "assignfeedbackcomments_editor": {
                "text": "Buen trabajo", "format": 1,
            },
        },
    }
    assert modos == [True]  # requiere token docente


def test_calificar_entrega_sin_comentario_omite_feedback():
    client = FakeClient({"mod_assign_save_grade": {}})
    tools, _ = registrar(client)

    tools["calificar_entrega"](asignacion_id=101, estudiante_id=21, nota=80)

    _, params = client.llamadas[0]
    assert "plugindata" not in params


def test_otorgar_prorroga_usa_extensiones():
    client = FakeClient({"mod_assign_save_user_extensions": {}})
    tools, modos = registrar(client)

    tools["otorgar_prorroga"](asignacion_id=101, estudiante_id=21,
                              nueva_fecha="1786000000")

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "mod_assign_save_user_extensions"
    assert params["assignmentid"] == 101
    assert params["userids"] == [21]
    assert params["dates"] == [1786000000]
    assert modos == [True]


def test_bloquear_y_desbloquear_entregas():
    client = FakeClient({
        "mod_assign_lock_submissions": {},
        "mod_assign_unlock_submissions": {},
    })
    tools, _ = registrar(client)

    tools["bloquear_entregas"](asignacion_id=101, estudiante_id=21)
    tools["desbloquear_entregas"](asignacion_id=101, estudiante_id=21)

    assert client.llamadas[0][0] == "mod_assign_lock_submissions"
    assert client.llamadas[1][0] == "mod_assign_unlock_submissions"
    assert client.llamadas[0][1]["userids"] == [21]


def test_revertir_a_borrador():
    client = FakeClient({"mod_assign_revert_submissions_to_draft": {}})
    tools, _ = registrar(client)

    tools["revertir_a_borrador"](asignacion_id=101, estudiante_id=21)

    assert client.llamadas[0][0] == "mod_assign_revert_submissions_to_draft"
    assert client.llamadas[0][1]["userids"] == [21]


def test_calificar_lote_envia_save_grades():
    client = FakeClient({"mod_assign_save_grades": {}})
    tools, modos = registrar(client)

    tools["calificar_lote"](calificaciones=[
        {"asignacion_id": 101, "estudiante_id": 21, "nota": 75,
         "comentario": "OK"},
        {"asignacion_id": 101, "estudiante_id": 22, "nota": 90},
    ])

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "mod_assign_save_grades"
    assert params["assignmentid"] == 101
    assert params["applytoall"] is False
    assert params["grades"][0] == {
        "userid": 21, "grade": "75",
        "attemptnumber": -1, "addattempt": 0,
        "workflowstate": "released",
        "advancedgradingdata": {},
        "plugindata": {
            "assignfeedbackcomments_editor": {"text": "OK", "format": 1},
        },
    }
    assert params["grades"][1]["userid"] == 22
    assert params["grades"][1]["plugindata"]["assignfeedbackcomments_editor"]["text"] == ""
    assert modos == [True]


def test_calificar_lote_agrupa_por_asignacion():
    """Dos asignaciones distintas = dos llamadas, nunca una con la primera."""
    client = FakeClient({"mod_assign_save_grades": {}})
    tools, modos = registrar(client)

    salida = tools["calificar_lote"](calificaciones=[
        {"asignacion_id": 101, "estudiante_id": 21, "nota": 75},
        {"asignacion_id": 202, "estudiante_id": 22, "nota": 90},
    ])

    assert len(client.llamadas) == 2
    ids = {params["assignmentid"]: params for _, params in client.llamadas}
    assert set(ids) == {101, 202}
    assert ids[101]["grades"][0]["userid"] == 21
    assert ids[202]["grades"][0]["userid"] == 22
    assert '"total": 2' in salida.replace("\n", "")


def test_calificar_lote_vacio_no_llama_al_cliente():
    client = FakeClient({})
    tools, _ = registrar(client)

    salida = tools["calificar_lote"](calificaciones=[])

    assert "error" in salida
    assert client.llamadas == []


def test_calificar_lote_incompleta_no_escribe():
    client = FakeClient({"mod_assign_save_grades": {}})
    tools, _ = registrar(client)

    salida = tools["calificar_lote"](calificaciones=[
        {"asignacion_id": 101, "estudiante_id": 21, "nota": 75},
        {"asignacion_id": 101, "nota": 80},
    ])

    assert "error" in salida
    assert client.llamadas == []
