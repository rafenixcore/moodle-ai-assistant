"""Tests de las tools de gestion: usuarios, matriculacion, grupos (Fase 3)."""
from moodle_mcp.server import to_json_text


class FakeClient:
    """MoodleClient falso: devuelve respuestas preprogramadas por funcion.

    Si el valor es una lista, se consume en orden (una respuesta por
    llamada), para simular fallbacks que reintentan.
    """
    def __init__(self, respuestas):
        self.respuestas = respuestas
        self.llamadas = []

    def call(self, wsfunction, **params):
        self.llamadas.append((wsfunction, params))
        resp = self.respuestas[wsfunction]
        if isinstance(resp, list):
            return resp.pop(0)
        return resp


def registrar(client):
    """Registra las tools de gestion y captura con que modo se llaman."""
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

    from moodle_mcp.tools.gestion import register_gestion
    register_gestion(FakeMCP(), get_client, to_json_text)
    return tools_registradas, modos_escritura


def test_buscar_usuario_es_lectura():
    client = FakeClient({"core_user_get_users": {"users": [
        {"id": 21, "fullname": "Estudiante 01", "email": "s1@test.local"},
    ]}})
    tools, modos = registrar(client)

    salida = tools["buscar_usuario"](username="student1")

    assert "coincidencia" in salida
    wsfunction, params = client.llamadas[0]
    assert wsfunction == "core_user_get_users"
    assert params == {"criteria[0][key]": "username",
                      "criteria[0][value]": "student1"}
    assert modos == [False]


def test_buscar_usuario_reintenta_con_email_y_firstname():
    # Moodle 5.2 solo expone username al propio usuario o con
    # moodle/user:viewalldetails: el criterio username devuelve [] para
    # otros usuarios. La tool debe reintentar con email y firstname (LIKE).
    client = FakeClient({"core_user_get_users": [
        {"users": []},
        {"users": []},
        {"users": [{"id": 21, "fullname": "Estudiante 01",
                    "email": "student1@example.local"}]},
    ]})
    tools, modos = registrar(client)

    salida = tools["buscar_usuario"](username="student1")

    assert '"coincidencia": "firstname"' in salida
    assert client.llamadas[0][1] == {"criteria[0][key]": "username",
                                     "criteria[0][value]": "student1"}
    assert client.llamadas[1][1] == {"criteria[0][key]": "email",
                                     "criteria[0][value]": "%student1%"}
    assert client.llamadas[2][1] == {"criteria[0][key]": "firstname",
                                     "criteria[0][value]": "%student1%"}
    assert modos == [False]


def test_buscar_usuario_devuelve_vacio_si_no_coincide():
    client = FakeClient({"core_user_get_users": [
        {"users": []}, {"users": []}, {"users": []},
    ]})
    tools, _ = registrar(client)

    salida = tools["buscar_usuario"](username="nadie")

    assert '"coincidencia": null' in salida
    assert len(client.llamadas) == 3


def test_crear_curso_envia_create_courses():
    client = FakeClient({"core_course_create_courses": [{"id": 8}]})
    tools, modos = registrar(client)

    salida = tools["crear_curso"](
        nombre="Ofimatica Aplicada", shortname="OFI2026",
        descripcion="<p>Curso de ofimatica</p>")

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "core_course_create_courses"
    assert params["courses"][0] == {
        "fullname": "Ofimatica Aplicada", "shortname": "OFI2026",
        "categoryid": 1, "summary": "<p>Curso de ofimatica</p>",
        "summaryformat": 1,
    }
    assert '"course_id": 8' in salida
    assert modos == [True]


def test_crear_usuario_envia_create_users():
    client = FakeClient({"core_user_create_users": {}})
    tools, modos = registrar(client)

    tools["crear_usuario"](username="student11", password="Clave123!",
                           firstname="Ana", lastname="Gomez",
                           email="ana@test.local")

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "core_user_create_users"
    assert params["users"][0] == {
        "username": "student11", "password": "Clave123!",
        "firstname": "Ana", "lastname": "Gomez", "email": "ana@test.local",
    }
    assert modos == [True]


def test_matricular_usuario_envia_enrol_manual():
    client = FakeClient({"enrol_manual_enrol_users": {}})
    tools, modos = registrar(client)

    tools["matricular_usuario"](curso_id=7, usuario_id=21)

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "enrol_manual_enrol_users"
    assert params["enrolments"][0] == {"roleid": 5, "userid": 21, "courseid": 7}
    assert modos == [True]


def test_matricular_usuario_mapea_roles():
    client = FakeClient({"enrol_manual_enrol_users": {}})
    tools, _ = registrar(client)

    tools["matricular_usuario"](curso_id=7, usuario_id=21, rol="teacher")
    _, params = client.llamadas[0]
    assert params["enrolments"][0]["roleid"] == 3

    tools["matricular_usuario"](curso_id=7, usuario_id=22,
                                rol="noneditingteacher")
    _, params = client.llamadas[1]
    assert params["enrolments"][0]["roleid"] == 4


def test_desmatricular_usuario():
    client = FakeClient({"enrol_manual_unenrol_users": {}})
    tools, modos = registrar(client)

    tools["desmatricular_usuario"](curso_id=7, usuario_id=21)

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "enrol_manual_unenrol_users"
    assert params["enrolments"][0] == {"userid": 21, "courseid": 7}
    assert modos == [True]


def test_crear_grupo():
    client = FakeClient({"core_group_create_groups": {}})
    tools, modos = registrar(client)

    tools["crear_grupo"](curso_id=7, nombre="Grupo A")

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "core_group_create_groups"
    # description es PARAM_RAW REQUERIDO en Moodle 5.2 (invalid_parameter
    # si se omite), aunque sea vacia.
    assert params["groups"][0] == {"courseid": 7, "name": "Grupo A",
                                   "description": ""}
    assert modos == [True]


def test_agregar_a_grupo():
    client = FakeClient({"core_group_add_group_members": {}})
    tools, modos = registrar(client)

    tools["agregar_a_grupo"](grupo_id=12, usuario_id=21)

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "core_group_add_group_members"
    assert params["members"][0] == {"groupid": 12, "userid": 21}
    assert modos == [True]


def test_crear_evento():
    client = FakeClient({"core_calendar_create_calendar_events": {}})
    tools, modos = registrar(client)

    tools["crear_evento"](nombre="Entrega parcial", fecha_ini_ts=1786000000,
                          curso_id=7)

    wsfunction, params = client.llamadas[0]
    assert wsfunction == "core_calendar_create_calendar_events"
    assert params["events"][0] == {
        "name": "Entrega parcial", "eventtype": "course",
        "timestart": 1786000000, "courseid": 7,
    }
    assert modos == [True]
