"""Add flashcard suspension and account-wide reverse-side preferences.

Revision ID: 0056_flashcard_management
Revises: 0055_sentence_order
"""

import sqlalchemy as sa

from alembic import op

revision = "0056_flashcard_management"
down_revision = "0055_sentence_order"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column(
        "flashcards",
        sa.Column("is_suspended", sa.Boolean(), nullable=False, server_default=sa.false()),
    )
    op.add_column(
        "users",
        sa.Column(
            "flashcard_back_content", sa.String(20), nullable=False, server_default="definition"
        ),
    )


def downgrade() -> None:
    op.drop_column("users", "flashcard_back_content")
    op.drop_column("flashcards", "is_suspended")
