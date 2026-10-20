"""Tests del cliente REST de Moodle."""
from urllib.parse import unquote

import httpx
import pytest

from moodle_mcp.client import MoodleAPIError, MoodleClient


class FakeTransport(httpx.BaseTransport):
    """Transporte que captura la peticion y devuelve una respuesta programada."""
    def __init__(self, payload, status=200):
        self.payload = payload
        self.status = status
        self.last_request = None

    def handle_request(self, request):
        self.last_request = request
        return httpx.Response(self.status, json=self.payload, request=request)


def test_call_envia_token_y_funcion():
    transport = FakeTransport({"courses": []})
    client = MoodleClient(url="http://moodle:8080", token="SECRETO",
                          transport=transport)

    result = client.call("mod_assign_get_assignments")

    assert result == {"courses": []}
    request = transport.last_request
    assert str(request.url) == "http://moodle:8080/webservice/rest/server.php"
    assert request.content == (
        b"wstoken=SECRETO&wsfunction=mod_assign_get_assignments&moodlewsrestformat=json"
    )


def test_call_pasa_parametros():
    transport = FakeTransport({})
    client = MoodleClient(url="http://moodle:8080", token="T", transport=transport)
    client.call("mod_assign_get_submissions", assignmentids_0=7)
    body = transport.last_request.content.decode()
    assert "assignmentids[0]=7" in body or "assignmentids_0=7" in body


def test_call_envia_form_urlencoded():
    # PHP solo puebla $_POST con Content-Type application/x-www-form-urlencoded;
    # con application/octet-stream Moodle no ve wstoken ni wsfunction.
    transport = FakeTransport({})
    client = MoodleClient(url="http://moodle:8080", token="T", transport=transport)
    client.call("mod_assign_get_assignments")
    assert transport.last_request.headers["content-type"].startswith(
        "application/x-www-form-urlencoded"
    )


def test_call_aplana_dicts_anidados():
    # Moodle REST espera assignfeedbackcomments_editor[text]=..., no dicts JSON.
    transport = FakeTransport({})
    client = MoodleClient(url="http://moodle:8080", token="T", transport=transport)
    client.call("mod_assign_save_grade",
                assignfeedbackcomments_editor={"text": "OK", "format": 1})
    body = unquote(transport.last_request.content.decode())
    assert "assignfeedbackcomments_editor[text]=OK" in body
    assert "assignfeedbackcomments_editor[format]=1" in body


def test_call_aplana_listas_de_dicts():
    # grades=[{userid, grade}] debe serializar a grades[0][userid]=...
    transport = FakeTransport({})
    client = MoodleClient(url="http://moodle:8080", token="T", transport=transport)
    client.call("mod_assign_save_grades",
                grades=[{"userid": 21, "grade": "75.5"}])
    body = unquote(transport.last_request.content.decode())
    assert "grades[0][userid]=21" in body
    assert "grades[0][grade]=75.5" in body


def test_call_aplana_listas_simples():
    # userids=[21] debe serializar a userids[0]=21
    transport = FakeTransport({})
    client = MoodleClient(url="http://moodle:8080", token="T", transport=transport)
    client.call("mod_assign_lock_submissions", userids=[21, 22])
    body = unquote(transport.last_request.content.decode())
    assert "userids[0]=21" in body
    assert "userids[1]=22" in body


def test_call_serializa_bools_como_1_y_0():
    # PARAM_BOOL de Moodle: "False" (str de Python) es truthy en PHP;
    # applytoall=false debe enviarse como applytoall=0.
    transport = FakeTransport({})
    client = MoodleClient(url="http://moodle:8080", token="T", transport=transport)
    client.call("mod_assign_save_grade", applytoall=False)
    body = unquote(transport.last_request.content.decode())
    assert "applytoall=0" in body
    assert "applytoall=False" not in body


def test_call_lanza_error_con_respuesta_exception():
    transport = FakeTransport({"exception": "accessdenied", "message": "sin permiso"})
    client = MoodleClient(url="http://moodle:8080", token="T", transport=transport)
    with pytest.raises(MoodleAPIError):
        client.call("mod_assign_save_grade")


def test_call_lanza_error_http():
    transport = FakeTransport({}, status=500)
    client = MoodleClient(url="http://moodle:8080", token="T", transport=transport)
    with pytest.raises(httpx.HTTPStatusError):
        client.call("mod_assign_get_assignments")
