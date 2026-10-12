"""Check the additive PostgreSQL migration offline; never apply it to a database."""

from io import StringIO
from pathlib import Path

import pytest
from alembic.config import Config
from alembic.script import ScriptDirectory
from sqlalchemy.dialects import postgresql
from sqlalchemy.schema import CreateColumn

from alembic import command
from app.core.config import settings
from app.models.flashcard import Flashcard
from app.models.user import User

BASE = "0055_sentence_order"
REVISION = "0056_flashcard_management"


@pytest.fixture(autouse=True)
def setup_db():
    """Override the create_all fixture: this file exercises the Alembic path."""


def test_upgrade_is_connected_additive_and_matches_model_defaults(monkeypatch):
    monkeypatch.setattr(
        settings, "DATABASE_URL", "postgresql+asyncpg://offline:offline@invalid.invalid/offline"
    )

    def reject_connection(*args, **kwargs):
        raise AssertionError("Offline check must not open a database connection")

    monkeypatch.setattr("sqlalchemy.ext.asyncio.create_async_engine", reject_connection)
    root = Path(__file__).resolve().parents[1]
    output = StringIO()
    config = Config(str(root / "alembic.ini"), output_buffer=output)
    config.set_main_option("script_location", str(root / "alembic"))
    scripts = ScriptDirectory.from_config(config)
    assert len(scripts.get_heads()) == 1
    assert REVISION in {revision.revision for revision in scripts.walk_revisions()}
    assert scripts.get_revision(REVISION).down_revision == BASE
    command.upgrade(config, f"{BASE}:{REVISION}", sql=True)
    sql = output.getvalue()
    for table, name in [
        (Flashcard.__table__, "is_suspended"),
        (User.__table__, "flashcard_back_content"),
    ]:
        definition = CreateColumn(table.c[name]).compile(dialect=postgresql.dialect())
        assert f"ALTER TABLE {table.name} ADD COLUMN {definition};" in sql
    assert sql.count("ADD COLUMN") == 2
    assert "DROP " not in sql
    assert "DELETE FROM " not in sql
    assert "UPDATE flashcards" not in sql
