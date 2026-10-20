"""Cliente delgado sobre los Web Services REST de Moodle."""
from urllib.parse import urlencode

import httpx


class MoodleAPIError(Exception):
    """Moodle devolvio un error de aplicacion (exception en el payload)."""


def _serializar(v):
    """Convierte valores al string que Moodle REST espera (PARAM_BOOL 1/0)."""
    if isinstance(v, bool):
        return "1" if v else "0"
    return v


def _aplanar(d: dict, prefijo: str = "") -> list[tuple[str, object]]:
    """Aplana dicts/listas al formato que Moodle REST espera.

    {"a": {"b": 1}} -> [("a[b]", 1)]   {"ids": [1, 2]} -> [("ids[0]", 1), ...]
    """
    items = []
    for k, v in d.items():
        clave = f"{prefijo}[{k}]" if prefijo else str(k)
        if isinstance(v, dict):
            items.extend(_aplanar(v, clave))
        elif isinstance(v, (list, tuple)):
            for i, item in enumerate(v):
                sub = f"{clave}[{i}]"
                if isinstance(item, dict):
                    items.extend(_aplanar(item, sub))
                else:
                    items.append((sub, _serializar(item)))
        else:
            items.append((clave, _serializar(v)))
    return items


class MoodleClient:
    """Envía peticiones a /webservice/rest/server.php y devuelve el JSON.

    El transporte es inyectable para poder testear sin red real.
    """

    def __init__(self, url: str, token: str,
                 transport: httpx.BaseTransport | None = None,
                 timeout: float = 30.0):
        self._endpoint = url.rstrip("/") + "/webservice/rest/server.php"
        self._token = token
        self._client = httpx.Client(transport=transport, timeout=timeout)

    def call(self, wsfunction: str, **params) -> dict:
        """Llama a una funcion WS y devuelve el cuerpo JSON decodificado.

        Lanza MoodleAPIError si Moodle responde con un error de aplicacion,
        y httpx.HTTPStatusError si el estado HTTP no es 2xx.
        """
        data = {
            "wstoken": self._token,
            "wsfunction": wsfunction,
            "moodlewsrestformat": "json",
            **params,
        }
        # _aplanar convierte dicts/listas al formato clave[sub] de Moodle REST;
        # content=bytes exige Content-Type explicito para que PHP rellene $_POST.
        response = self._client.post(
            self._endpoint,
            content=urlencode(_aplanar(data)),
            headers={"Content-Type": "application/x-www-form-urlencoded"},
        )
        response.raise_for_status()
        payload = response.json()
        if isinstance(payload, dict) and "exception" in payload:
            raise MoodleAPIError(
                f"{payload.get('exception')}: {payload.get('message', '')}"
            )
        return payload
