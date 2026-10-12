"""Persist idempotent flashcard review responses.

Revision ID: 0057_flashcard_review_receipts
Revises: 0056_flashcard_management
"""

import sqlalchemy as sa

from alembic import op

revision = "0057_flashcard_review_receipts"
down_revision = "0056_flashcard_management"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "flashcard_review_receipts",
        sa.Column("flashcard_id", sa.Integer(), nullable=False),
        sa.Column("review_id", sa.String(36), nullable=False),
        sa.Column("quality", sa.Integer(), nullable=False),
        sa.Column("response", sa.JSON(), nullable=False),
        sa.ForeignKeyConstraint(["flashcard_id"], ["flashcards.id"], ondelete="CASCADE"),
        sa.PrimaryKeyConstraint("flashcard_id", "review_id"),
    )


def downgrade() -> None:
    op.drop_table("flashcard_review_receipts")
