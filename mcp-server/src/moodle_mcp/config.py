"""Configuracion leida de variables de entorno (sin secretos en el codigo)."""
import os


class ConfigError(Exception):
    """Falta una variable de entorno obligatoria."""


def get_config() -> dict:
    url = os.environ.get("MOODLE_URL", "").strip()
    token = os.environ.get("MOODLE_TOKEN_LECTURA", "").strip() or os.environ.get("MOODLE_TOKEN", "").strip()
    token_write = os.environ.get("MOODLE_TOKEN_DOCENTE", "").strip()
    if not url:
        raise ConfigError("Falta MOODLE_URL (ej. http://localhost:8080)")
    if not token:
        raise ConfigError("Falta MOODLE_TOKEN_LECTURA (generado en Fase 1)")
    return {"url": url, "token": token, "token_write": token_write}
