"""Tools de asignaciones: lectura (Fase 2) y escritura (Fase 3)."""
import datetime


def register_asignaciones(mcp, get_client, to_json_text):
    @mcp.tool()
    def listar_asignaciones(curso_id: int | None = None) -> str:
        """Lista las asignaciones (tareas) con id, nombre, fecha limite y nota maxima.

        Args:
            curso_id: id del curso a filtrar; omitir para ver todos los cursos.
        """
        client = get_client()
        params = {"courseids[0]": curso_id} if curso_id else {}
        data = client.call("mod_assign_get_assignments", **params)
        resumen = []
        for course in data.get("courses", []):
            for a in course.get("assignments", []):
                duedate = datetime.datetime.fromtimestamp(
                    a["duedate"], tz=datetime.timezone.utc
                ).isoformat() if a.get("duedate") else None
                resumen.append({
                    "assignment_id": a["id"],
                    "course_id": course["id"],
                    "course": course["fullname"],
                    "nombre": a["name"],
                    "fecha_limite": duedate,
                    # mod_assign_get_assignments devuelve la nota maxima en el
                    # campo `grade` de cada asignacion. El antiguo lookup en
                    # course["grades"] no existe en esa respuesta y devolvia None.
                    "nota_maxima": a.get("grade"),
                })
        return to_json_text(resumen)

    @mcp.tool()
    def listar_entregas(asignacion_id: int, estudiante_id: int | None = None) -> str:
        """Lista las entregas (submissions) de una asignacion.

        Args:
            asignacion_id: id de la asignacion (assignment_id de listar_asignaciones).
            estudiante_id: filtrar por un estudiante; omitir para todas.
        """
        client = get_client()
        params = {"assignmentids[0]": asignacion_id}
        data = client.call("mod_assign_get_submissions", **params)
        salida = []
        for a in data.get("assignments", []):
            for s in a.get("submissions", []):
                usuario = s.get("userid")
                if estudiante_id and usuario != estudiante_id:
                    continue
                salida.append({
                    "submission_id": s["id"],
                    "userid": usuario,
                    "status": s.get("status"),
                    "status_nombre": {
                        "new": "sin entregar", "draft": "borrador",
                        "submitted": "entregado",
                    }.get(s.get("status"), s.get("status")),
                    "fecha_entrega": datetime.datetime.fromtimestamp(
                        s["timemodified"], tz=datetime.timezone.utc
                    ).isoformat() if s.get("timemodified") else None,
                })
        return to_json_text(salida)

    @mcp.tool()
    def tareas_urgentes() -> str:
        """Todas las tareas con fecha limite, ordenadas por urgencia.

        Devuelve vencidas primero (dias en negativo) y luego las proximas.
        """
        client = get_client()
        data = client.call("mod_assign_get_assignments")
        ahora = datetime.datetime.now(datetime.timezone.utc)
        resumen = []
        for course in data.get("courses", []):
            for a in course.get("assignments", []):
                if not a.get("duedate"):
                    continue
                due = datetime.datetime.fromtimestamp(
                    a["duedate"], tz=datetime.timezone.utc)
                dias = (due - ahora).days
                resumen.append({
                    "assignment_id": a["id"],
                    "course": course["fullname"],
                    "nombre": a["name"],
                    "fecha_limite": due.isoformat(),
                    "dias_restantes": dias,
                    "estado": "VENCIDA" if dias < 0 else "por vencer",
                })
        resumen.sort(key=lambda r: r["dias_restantes"])
        return to_json_text(resumen)

    @mcp.tool()
    def estado_estudiante(asignacion_id: int, estudiante_id: int) -> str:
        """Estado de una entrega concreta: entregada, en borrador, calificada, etc."""
        client = get_client()
        data = client.call(
            "mod_assign_get_submission_status",
            assignid=asignacion_id, userid=estudiante_id)
        return to_json_text(data)

    # --- escritura (Fase 3): requieren MOODLE_TOKEN_DOCENTE ---

    @mcp.tool()
    def calificar_entrega(asignacion_id: int, estudiante_id: int, nota: float,
                          comentario: str = "") -> str:
        """⚠️ ESCRITURA IRREVERSIBLE. Guarda nota y feedback de una entrega.

        Requiere token docente y confirmacion humana en el cliente.
        """
        client = get_client(escritura=True)
        params = {
            "assignmentid": asignacion_id,
            "userid": estudiante_id,
            # PARAM_FLOAT en Moodle 5.2: la nota viaja como string parseable.
            "grade": str(nota),
            "attemptnumber": -1,
            "addattempt": 0,
            "workflowstate": "released",
            # PARAM_BOOL requerido (sin default) en Moodle 5.2: applytoall=false.
            "applytoall": False,
            "advancedgradingdata": {},
        }
        if comentario:
            # El feedback va DENTRO de plugindata; al nivel raiz Moodle
            # responde "Unexpected keys ... detected in parameter array".
            params["plugindata"] = {
                "assignfeedbackcomments_editor": {
                    "text": comentario, "format": 1}}
        data = client.call("mod_assign_save_grade", **params)
        return to_json_text({"ok": True, "asignacion_id": asignacion_id,
                             "estudiante_id": estudiante_id, "nota": nota})

    @mcp.tool()
    def otorgar_prorroga(asignacion_id: int, estudiante_id: int,
                         nueva_fecha: str) -> str:
        """⚠️ ESCRITURA. Extiende la fecha limite para un estudiante.

        Args:
            nueva_fecha: timestamp Unix (segundos) de la nueva fecha limite.
        """
        client = get_client(escritura=True)
        data = client.call(
            "mod_assign_save_user_extensions",
            assignmentid=asignacion_id,
            userids=[estudiante_id],
            dates=[int(nueva_fecha)],
        )
        return to_json_text({"ok": True, "extension": int(nueva_fecha)})

    @mcp.tool()
    def bloquear_entregas(asignacion_id: int, estudiante_id: int) -> str:
        """⚠️ ESCRITURA. Impide que el estudiante modifique su entrega."""
        client = get_client(escritura=True)
        client.call("mod_assign_lock_submissions",
                    assignmentid=asignacion_id, userids=[estudiante_id])
        return to_json_text({"ok": True, "bloqueado": estudiante_id})

    @mcp.tool()
    def desbloquear_entregas(asignacion_id: int, estudiante_id: int) -> str:
        """⚠️ ESCRITURA. Permite de nuevo modificar la entrega."""
        client = get_client(escritura=True)
        client.call("mod_assign_unlock_submissions",
                    assignmentid=asignacion_id, userids=[estudiante_id])
        return to_json_text({"ok": True, "desbloqueado": estudiante_id})

    @mcp.tool()
    def revertir_a_borrador(asignacion_id: int, estudiante_id: int) -> str:
        """⚠️ ESCRITURA. Devuelve la entrega a borrador (para permitir correccion)."""
        client = get_client(escritura=True)
        client.call("mod_assign_revert_submissions_to_draft",
                    assignmentid=asignacion_id, userids=[estudiante_id])
        return to_json_text({"ok": True, "revertido": estudiante_id})

    @mcp.tool()
    def calificar_lote(calificaciones: list[dict]) -> str:
        """⚠️ ESCRITURA EN LOTE. Recibe [{asignacion_id, estudiante_id, nota, comentario}].

        Usa mod_assign_save_grades en una sola llamada. Requiere aprobacion humana.
        """
        if not calificaciones:
            return to_json_text({"error": "calificaciones vacia: no hay nada que guardar"})

        requeridas = ("asignacion_id", "estudiante_id", "nota")
        por_asignacion: dict = {}
        for c in calificaciones:
            faltan = [k for k in requeridas if k not in c]
            if faltan:
                return to_json_text({
                    "error": f"calificacion incompleta: faltan {faltan}",
                    "calificacion": {k: c.get(k) for k in requeridas},
                })
            por_asignacion.setdefault(c["asignacion_id"], []).append(c)

        # mod_assign_save_grades trabaja sobre UNA asignacion por llamada: se
        # agrupa para que una nota nunca acabe en la asignacion equivocada.
        client = get_client(escritura=True)
        enviadas = 0
        for asignacion_id, grupo in por_asignacion.items():
            grades = [{
                "userid": c["estudiante_id"], "grade": str(c["nota"]),
                "attemptnumber": -1, "addattempt": 0,
                "workflowstate": "released",
                "advancedgradingdata": {},
                "plugindata": {
                    "assignfeedbackcomments_editor": {
                        "text": c.get("comentario", ""), "format": 1}},
            } for c in grupo]
            client.call("mod_assign_save_grades",
                        assignmentid=asignacion_id,
                        applytoall=False,
                        grades=grades)
            enviadas += len(grades)

        return to_json_text({
            "ok": True,
            "total": enviadas,
            "asignaciones": len(por_asignacion),
        })
