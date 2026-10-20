"""Clonacion de cursos via Web Services (backup/restore asincrono).

Moodle 5.2 descarta dos WS nativos del plan:
- core_backup_get_course_backup_information (era de Moodle 4.x).
- core_backup_submit_copy_form: el copy_form de formslib exige el marcador
  _qf__ y un sesskey de sesion real; un cliente WS stateless (token
  permanente) no puede producirlo (falla con missingparam/copyformfail).
  Verificado en el contenedor.

La copia se lanza con local_mcpbridge_copy_course (plugin PHP del
proyecto): hace el mismo trabajo que la UI (copy_helper::process_formdata
+ create_copy) del lado servidor, con la sesion del token user.

El curso origen se valida con core_webservice_get_site_info (userid del
token) + core_enrol_get_users_courses (cursos propios: un teacher solo
puede clonar sus propios cursos); el estado del job se consulta con
core_backup_get_copy_progress.
"""


def register_clonacion(mcp, get_client, to_json_text):
    @mcp.tool()
    def clonar_curso(curso_origen_id: int, nombre_nuevo: str,
                     shortname_nuevo: str, categoria_id: int = 1) -> str:
        """⚠️ ESCRITURA. Clona un curso completo (actividades incluidas).

        La copia es ASINCRONA: Moodle encola un job (adhoc task) que corre
        en el siguiente cron. Puede tardar minutos. El job se consulta con
        estado_clonacion o en la UI (Progreso de copias del curso).

        Args:
            curso_origen_id: id del curso a clonar (campo course_id de
                listar_cursos).
            nombre_nuevo: nombre completo del curso clonado.
            shortname_nuevo: nombre corto unico del curso clonado.
            categoria_id: categoria destino (default 1 = Miscelanea).
        """
        # Validar que el curso existe (y es del token user) antes de
        # encolar la copia: un teacher solo puede clonar sus propios cursos.
        client = get_client()
        info = client.call("core_webservice_get_site_info")
        cursos = client.call("core_enrol_get_users_courses",
                             userid=info["userid"])
        curso = next((c for c in cursos if c["id"] == curso_origen_id), None)
        if not curso:
            return to_json_text({"ok": False,
                                 "error": f"El curso {curso_origen_id} no "
                                          f"existe (o no es del token user)."})
        # El clon hereda la fecha de inicio del curso origen; si no tiene
        # (startdate=0) el plugin aplica su default (manana a medianoche).
        startdate = curso.get("startdate", 0)

        client_escritura = get_client(escritura=True)
        data = client_escritura.call(
            "local_mcpbridge_copy_course",
            courseid=curso_origen_id,
            fullname=nombre_nuevo,
            shortname=shortname_nuevo,
            category=categoria_id,
            visible=1,
            startdate=startdate)
        copyids = {"backupid": data["backupid"],
                   "restoreid": data["restoreid"]}
        return to_json_text({
            "ok": True,
            "copyids": copyids,
            "courseid_nuevo": data["courseid"],
            "nota": "La copia corre en segundo plano (cron); consulta "
                    "estado_clonacion."})

    @mcp.tool()
    def estado_clonacion(backupid: str, restoreid: str,
                         operation: str = "copy") -> str:
        """Consulta el progreso de un job de clonacion (solo lectura).

        Usa el token docente: el WS valida el contexto del curso clonado
        (nuevo, sin matriculaciones) y el job pertenece al usuario que
        lanzo la copia.

        Args:
            backupid/restoreid/operation: los copyids devueltos por
                clonar_curso.
        """
        client = get_client(escritura=True)
        data = client.call("core_backup_get_copy_progress",
                           copies=[{"backupid": backupid,
                                    "restoreid": restoreid,
                                    "operation": operation}])
        return to_json_text(data)
