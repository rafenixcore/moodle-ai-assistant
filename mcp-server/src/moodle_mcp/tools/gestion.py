"""Tools de gestion: cursos, usuarios, matriculacion, grupos y calendario."""

# Roles estandar de Moodle (tabla mdl_role): student/teacher/noneditingteacher.
ROLE_IDS = {"student": 5, "teacher": 3, "noneditingteacher": 4}


def _primer_id(data):
    """Extrae el id del primer elemento creado por el WS.

    Las respuestas de create_* varian: lista directa (users, groups),
    dict con la lista en una key (events) o el registro solo (dict con
    id). Devuelve None si no hay datos.
    """
    if isinstance(data, dict):
        for lista in data.values():
            if isinstance(lista, list) and lista:
                return lista[0].get("id")
        return data.get("id")
    if isinstance(data, list) and data:
        return data[0].get("id")
    return None


def register_gestion(mcp, get_client, to_json_text):
    @mcp.tool()
    def buscar_usuario(username: str) -> str:
        """Busca un usuario por username, email o nombre (lectura).

        Limitacion de Moodle 5.2: el campo username solo se incluye en los
        detalles del propio usuario o de quien tiene moodle/user:viewalldetails,
        asi que el criterio username del WS no devuelve otros usuarios con un
        token de docente. La tool reintenta con email y firstname (LIKE
        parcial) y devuelve en que campo encontro la coincidencia.

        Args:
            username: username, email o nombre parcial a buscar.
        """
        client = get_client()
        for key in ("username", "email", "firstname"):
            valor = username if key == "username" else f"%{username}%"
            data = client.call(
                "core_user_get_users",
                **{"criteria[0][key]": key,
                   "criteria[0][value]": valor},
            )
            if data.get("users"):
                usuarios = [
                    {"user_id": u["id"], "fullname": u.get("fullname"),
                     "email": u.get("email")}
                    for u in data["users"]
                ]
                return to_json_text({"coincidencia": key,
                                     "usuarios": usuarios})
        return to_json_text({"coincidencia": None, "usuarios": []})

    @mcp.tool()
    def crear_curso(nombre: str, shortname: str, descripcion: str = "",
                    categoria_id: int = 1) -> str:
        """⚠️ ESCRITURA. Crea un curso nuevo (vacio, sin actividades).

        Args:
            nombre: nombre completo del curso.
            shortname: nombre corto unico (sin espacios).
            descripcion: texto HTML de la descripcion general del curso.
            categoria_id: categoria destino (default 1 = Miscelanea).
        """
        client = get_client(escritura=True)
        data = client.call("core_course_create_courses", courses=[{
            "fullname": nombre, "shortname": shortname,
            "categoryid": categoria_id, "summary": descripcion,
            "summaryformat": 1,
        }])
        return to_json_text({"ok": True, "course_id": _primer_id(data)})

    @mcp.tool()
    def crear_usuario(username: str, password: str, firstname: str,
                      lastname: str, email: str) -> str:
        """⚠️ ESCRITURA. Crea un usuario nuevo en el sitio.

        Requiere moodle/user:createuser (permiso de administracion del
        sitio): el rol editingteacher NO lo tiene; usa un token de admin.
        """
        client = get_client(escritura=True)
        data = client.call("core_user_create_users", users=[{
            "username": username, "password": password,
            "firstname": firstname, "lastname": lastname, "email": email,
        }])
        return to_json_text({"ok": True, "user_id": _primer_id(data)})

    @mcp.tool()
    def matricular_usuario(curso_id: int, usuario_id: int,
                           rol: str = "student") -> str:
        """⚠️ ESCRITURA. Matricula a un usuario en un curso.

        Args:
            rol: student (5), teacher (3) o noneditingteacher (4).
        """
        client = get_client(escritura=True)
        if rol not in ROLE_IDS:
            return to_json_text({"error": f"rol invalido: {rol}. "
                                          f"Validos: {list(ROLE_IDS)}"})
        client.call("enrol_manual_enrol_users", enrolments=[{
            "roleid": ROLE_IDS[rol], "userid": usuario_id,
            "courseid": curso_id,
        }])
        return to_json_text({"ok": True, "usuario": usuario_id,
                             "curso": curso_id, "rol": rol})

    @mcp.tool()
    def desmatricular_usuario(curso_id: int, usuario_id: int) -> str:
        """⚠️ ESCRITURA. Desmatricula a un usuario de un curso."""
        client = get_client(escritura=True)
        client.call("enrol_manual_unenrol_users", enrolments=[{
            "userid": usuario_id, "courseid": curso_id,
        }])
        return to_json_text({"ok": True, "usuario": usuario_id,
                             "curso": curso_id})

    @mcp.tool()
    def crear_grupo(curso_id: int, nombre: str) -> str:
        """⚠️ ESCRITURA. Crea un grupo dentro de un curso."""
        client = get_client(escritura=True)
        data = client.call("core_group_create_groups", groups=[{
            "courseid": curso_id, "name": nombre, "description": "",
        }])
        return to_json_text({"ok": True, "grupo_id": _primer_id(data)})

    @mcp.tool()
    def agregar_a_grupo(grupo_id: int, usuario_id: int) -> str:
        """⚠️ ESCRITURA. Agrega a un usuario a un grupo existente."""
        client = get_client(escritura=True)
        client.call("core_group_add_group_members", members=[{
            "groupid": grupo_id, "userid": usuario_id,
        }])
        return to_json_text({"ok": True, "grupo": grupo_id,
                             "usuario": usuario_id})

    @mcp.tool()
    def crear_evento(nombre: str, fecha_ini_ts: int,
                     curso_id: int | None = None) -> str:
        """⚠️ ESCRITURA. Crea un evento en el calendario (curso o sitio).

        Args:
            fecha_ini_ts: timestamp Unix de inicio del evento.
        """
        client = get_client(escritura=True)
        evento = {
            "name": nombre, "eventtype": "course",
            "timestart": fecha_ini_ts,
        }
        if curso_id:
            evento["courseid"] = curso_id
        data = client.call("core_calendar_create_calendar_events",
                           events=[evento])
        return to_json_text({"ok": True, "evento_id": _primer_id(data)})
