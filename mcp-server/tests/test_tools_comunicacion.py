"""Tests de las tools de comunicacion: foros, discusiones y mensajes (Fase 3)."""
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
    """Registra las tools de comunicacion y captura con que modo se llaman."""
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

    from moodle_mcp.tools.comunicacion import register_comunicacion
    register_comunicacion(FakeMCP(), get_client, to_json_text)
    return tools_registradas, modos_escritura


def test_listar_foros_es_lectura():
    # El WS real devuelve la lista de foros directamente (no {forums: [...]}).
    client = FakeClient({"mod_forum_get_forums_by_courses": [
        {"id": 1, "name": "Avisos", "type": "news"},
    ]})
    tools, modos = registrar(client)

    salida = tools["listar_foros"](curso_id=2)

    assert "Avisos" in salida
    wsfunction, params = client.llamadas[0]
    assert wsfunction == "mod_forum_get_forums_by_courses"
    assert params == {"courseids": [2]}
    assert modos == [False]


def test_listar_discusiones_es_lectura():
    client = FakeClient({"mod_forum_get_forum_discussions": {"discussions": [
        {"id": 41, "discussion": 7, "subject": "Tema inicial",
         "userfullname": "Estudiante 01"},
    ]}})
    tools, modos = registrar(client)

    salida = tools["listar_discusiones"](foro_id=1)

    assert "Tema inicial" in salida
    wsfunction, params = client.llamadas[0]
    assert wsfunction == "mod_forum_get_forum_discussions"
    assert params == {"forumid": 1}
    assert modos == [False]


def test_publicar_anuncio_es_escritura():
    client = FakeClient({"mod_forum_add_discussion": {"discussionid": 7,
                                                       "warnings": []}})
    tools, modos = registrar(client)

    salida = tools["publicar_anuncio"](
        foro_id=1, asunto="Recordatorio", mensaje="<p>Entrega parcial</p>")

    assert '"discusion_id": 7' in salida
    wsfunction, params = client.llamadas[0]
    assert wsfunction == "mod_forum_add_discussion"
    # Firma real de Moodle 5.2: sin messageformat (solo html) ni options.
    assert params == {"forumid": 1, "subject": "Recordatorio",
                      "message": "<p>Entrega parcial</p>"}
    assert modos == [True]


def test_responder_discusion_es_escritura():
    client = FakeClient({"mod_forum_add_discussion_post": {"postid": 42,
                                                            "warnings": []}})
    tools, modos = registrar(client)

    salida = tools["responder_discusion"](
        post_id=41, asunto="Re: Tema", mensaje="Gracias")

    assert '"post_id": 42' in salida
    wsfunction, params = client.llamadas[0]
    assert wsfunction == "mod_forum_add_discussion_post"
    assert params == {"postid": 41, "subject": "Re: Tema", "message": "Gracias"}
    assert modos == [True]


def test_enviar_mensaje_es_escritura():
    # El WS devuelve la lista de mensajes directamente (no {messages: [...]}).
    client = FakeClient({"core_message_send_messages_to_conversation": [
        {"id": 99}]})
    tools, modos = registrar(client)

    salida = tools["enviar_mensaje"](conversacion_id=5, texto="Hola")

    assert '"message_id": 99' in salida
    wsfunction, params = client.llamadas[0]
    assert wsfunction == "core_message_send_messages_to_conversation"
    assert params == {"conversationid": 5,
                      "messages": [{"text": "Hola", "textformat": 0}]}
    assert modos == [True]
