"""Tests de config.py: lectura de variables de entorno sin secretos."""
import pytest

from moodle_mcp.config import ConfigError, get_config


def test_error_cuando_falta_url(monkeypatch):
    monkeypatch.delenv("MOODLE_URL", raising=False)
    monkeypatch.setenv("MOODLE_TOKEN_LECTURA", "tok")
    with pytest.raises(ConfigError):
        get_config()


def test_error_cuando_falta_token(monkeypatch):
    monkeypatch.setenv("MOODLE_URL", "http://localhost:8080")
    monkeypatch.delenv("MOODLE_TOKEN_LECTURA", raising=False)
    monkeypatch.delenv("MOODLE_TOKEN", raising=False)
    with pytest.raises(ConfigError):
        get_config()


def test_valores_correctos_cuando_estan_definidos(monkeypatch):
    monkeypatch.setenv("MOODLE_URL", "http://localhost:8080")
    monkeypatch.setenv("MOODLE_TOKEN_LECTURA", "tok-lectura")
    monkeypatch.setenv("MOODLE_TOKEN_DOCENTE", "tok-docente")
    assert get_config() == {
        "url": "http://localhost:8080",
        "token": "tok-lectura",
        "token_write": "tok-docente",
    }


def test_token_write_es_opcional(monkeypatch):
    monkeypatch.setenv("MOODLE_URL", "http://localhost:8080")
    monkeypatch.setenv("MOODLE_TOKEN_LECTURA", "tok-lectura")
    monkeypatch.delenv("MOODLE_TOKEN_DOCENTE", raising=False)
    cfg = get_config()
    assert cfg["token_write"] == ""


def test_fallback_a_moodle_token_legacy(monkeypatch):
    monkeypatch.setenv("MOODLE_URL", "http://localhost:8080")
    monkeypatch.delenv("MOODLE_TOKEN_LECTURA", raising=False)
    monkeypatch.setenv("MOODLE_TOKEN", "tok-legacy")
    assert get_config()["token"] == "tok-legacy"
