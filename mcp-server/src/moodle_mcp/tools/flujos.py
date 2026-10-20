"""Flujos compuestos: tools que combinan varias llamadas WS en un informe.

Fase 5: analizar -> proponer -> aprobar -> aplicar. Este modulo solo
contiene tools de LECTURA; la escritura del flujo la hace el LLM con
calificar_lote sobre las calificaciones que el usuario aprueba. Sin
calificaciones aprobadas no hay llamada de escritura (barrera humana).
"""


def register_flujos(mcp, get_client, to_json_text):
    @mcp.tool()
    def analizar_entregas_pendientes(asignacion_id: int,
                                     fecha_limite_ts: int = 0) -> str:
        """Analiza el estado de las entregas de una asignacion (solo lectura).

        Devuelve un informe estructurado: quien entrego, quien no, que
        entregas no tienen nota y cuales son tardias. Es la fase ANALIZAR
        del flujo analizar->proponer->aprobar->aplicar: este tool nunca
        escribe, la escritura la hace calificar_lote solo despues de que
        el usuario apruebe las calificaciones propuestas.

        Args:
            asignacion_id: id de la asignacion (campo assignment_id de
                listar_asignaciones).
            fecha_limite_ts: timestamp Unix de la fecha limite (del campo
                duedate de listar_asignaciones) para marcar entregas tardias.
                Con 0 se omite el analisis de tardias.
        """
        client = get_client()
        subs = client.call("mod_assign_get_submissions",
                           assignmentids=[asignacion_id], status="submitted")
        grades = client.call("mod_assign_get_grades",
                             assignmentids=[asignacion_id])
        participantes = client.call(
            "mod_assign_list_participants", assignid=asignacion_id,
            groupid=0, filter="", skip=0, limit=0,
            onlyids=1, includeenrolments=1)

        # Moodle 5.2 envuelve submissions y grades en assignments[].
        subs_por_user = {s["userid"]: s for s in
                         subs.get("assignments", [{}])[0].get("submissions", [])}
        nota_por_user = {g["userid"]: float(g["grade"]) for g in
                         grades.get("assignments", [{}])[0].get("grades", [])}

        entregados, no_entregados, sin_nota, tardias = [], [], [], 0
        for p in participantes:
            uid = p["id"]
            if p.get("submitted"):
                sub = subs_por_user.get(uid, {})
                nota = nota_por_user.get(uid)
                tarde = bool(fecha_limite_ts
                             and sub.get("timemodified", 0) > fecha_limite_ts)
                if tarde:
                    tardias += 1
                entregados.append({"user_id": uid,
                                   "nombre": p.get("fullname"),
                                   "submission_id": sub.get("id"),
                                   "nota": nota, "tardia": tarde})
                if nota is None:
                    sin_nota.append({"user_id": uid,
                                     "nombre": p.get("fullname"),
                                     "submission_id": sub.get("id"),
                                     "tardia": tarde})
            else:
                no_entregados.append({"user_id": uid,
                                      "nombre": p.get("fullname"),
                                      "estado": p.get("submissionstatus",
                                                      "ninguno")})

        return to_json_text({
            "asignacion_id": asignacion_id,
            "total_estudiantes": len(participantes),
            "fecha_limite_ts": fecha_limite_ts,
            "entregados": entregados,
            "no_entregados": no_entregados,
            "entregas_sin_nota": sin_nota,
            "resumen": {"entregados": len(entregados),
                        "no_entregados": len(no_entregados),
                        "sin_nota": len(sin_nota),
                        "tardias": tardias},
        })

    @mcp.tool()
    def resumir_entrega(submission_id: int) -> str:
        """(solo lectura) Devuelve el texto de una entrega para que la IA del
        cliente la resuma.

        Camino de IA del flujo (ver docs/ia_nativa.md): el servidor MCP NO
        duplica logica de IA (YAGNI). Este tool solo extrae el texto; el
        resumen lo escribe el cliente y se guarda como feedback con
        calificar_entrega (plugin de comentarios). El provider nativo de
        Moodle (Ollama/DeepSeek local, ver scripts/cli/ai_config.php) queda
        para las acciones de IA DENTRO de Moodle (editor, Course Assistant).

        Args:
            submission_id: id de la entrega (campo submission_id de
                listar_entregas o analizar_entregas_pendientes).
        """
        # El texto de la entrega no lo expone mod_assign_get_submissions
        # (solo fileareas): va por el WS del plugin local_mcpbridge.
        client = get_client()
        data = client.call("local_mcpbridge_get_submission_text",
                           submissionid=submission_id)
        return to_json_text(data)
