"""Fixtures compartidas."""
import pytest


@pytest.fixture
def fake_response():
    """Construye una respuesta HTTP falsa para inyectar en MoodleClient."""
    def _make(payload: dict, status: int = 200):
        return type("FakeResponse", (), {
            "status_code": status,
            "json": lambda self: payload,
            "raise_for_status": lambda self: None,
        })()
    return _make
