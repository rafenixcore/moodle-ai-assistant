"""Tools de comunicacion: foros, discusiones y mensajes directos."""


def register_comunicacion(mcp, get_client, to_json_text):
    @mcp.tool()
    def listar_foros(curso_id: int) -> str:
        """Lista los foros de un curso (lectura)."""
        client = get_client()
        data = client.call("mod_forum_get_forums_by_courses",
                           courseids=[curso_id])
        # El WS devuelve la lista de foros directamente.
        foros = data if isinstance(data, list) else data.get("forums", [])
        return to_json_text({
            "curso_id": curso_id,
            "foros": [
                {"foro_id": f["id"], "nombre": f.get("name"),
                 "tipo": f.get("type")}
                for f in foros
            ],
        })

    @mcp.tool()
    def listar_discusiones(foro_id: int) -> str:
        """Lista las discusiones de un foro (lectura).

        Devuelve post_id (id del primer post) para usar en
        responder_discusion.
        """
        client = get_client()
        data = client.call("mod_forum_get_forum_discussions",
                           forumid=foro_id)
        discusiones = [
            {"discusion_id": d.get("discussion"), "post_id": d.get("id"),
             "asunto": d.get("subject"), "autor": d.get("userfullname")}
            for d in data.get("discussions", [])
        ]
        return to_json_text({"foro_id": foro_id,
                             "discusiones": discusiones})

    @mcp.tool()
    def publicar_anuncio(foro_id: int, asunto: str, mensaje: str) -> str:
        """⚠️ ESCRITURA. Publica una discusion nueva en un foro.

        El mensaje se interpreta como HTML (formato del WS de Moodle).
        """
        client = get_client(escritura=True)
        data = client.call("mod_forum_add_discussion",
                           forumid=foro_id, subject=asunto, message=mensaje)
        return to_json_text({"ok": True,
                             "discusion_id": data.get("discussionid")})

    @mcp.tool()
    def responder_discusion(post_id: int, asunto: str, mensaje: str) -> str:
        """⚠️ ESCRITURA. Responde al primer post de una discusion.

        Args:
            post_id: id del post a responder (el campo post_id que
                devuelve listar_discusiones).
        """
        client = get_client(escritura=True)
        data = client.call("mod_forum_add_discussion_post",
                           postid=post_id, subject=asunto, message=mensaje)
        return to_json_text({"ok": True, "post_id": data.get("postid")})

    @mcp.tool()
    def enviar_mensaje(conversacion_id: int, texto: str) -> str:
        """⚠️ ESCRITURA. Envia un mensaje a una conversacion (chat interno).

        Requiere el id de una conversacion existente del usuario del token.
        """
        client = get_client(escritura=True)
        data = client.call("core_message_send_messages_to_conversation",
                           conversationid=conversacion_id,
                           messages=[{"text": texto, "textformat": 0}])
        # El WS devuelve la lista de mensajes directamente.
        enviados = (data if isinstance(data, list)
                    else data.get("messages", []))
        return to_json_text({"ok": True,
                             "message_id": (enviados[0].get("id")
                                            if enviados else None)})
