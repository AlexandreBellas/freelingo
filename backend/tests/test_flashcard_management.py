"""Library and management contracts, preserving creation and scheduling semantics."""

from datetime import date, timedelta
from unittest.mock import AsyncMock
from uuid import uuid4

import pytest
from sqlalchemy import func, select

from app.models.flashcard import Flashcard
from app.models.progress import Progress
from tests.conftest import make_study_plan


@pytest.fixture
async def plan(db_session, test_user):
    result = await make_study_plan(
        db_session,
        user_id=test_user[0].id,
        cefr_level="A1",
        is_active=True,
        duration_weeks=4,
        days_per_week=4,
    )
    await db_session.commit()
    return result


async def card_in(db, plan, word="book", **values):
    card = Flashcard(
        user_id=plan.user_id,
        study_plan_id=plan.id,
        word=word,
        definition="An object with pages",
        example_sentence="I read a book.",
        translation="libro",
        **values,
    )
    db.add(card)
    await db.commit()
    return card


def content(word="book", **changes):
    return {
        "word": word,
        "definition": "A better explanation",
        "example_sentence": "",
        "translation": "libro",
        **changes,
    }


def scheduling(card):
    return card.ease_factor, card.interval, card.repetitions, card.next_review


async def test_library_includes_all_sources_but_legacy_vocabulary_stays_saved_only(
    client, test_user, db_session, plan
):
    generated = await card_in(db_session, plan)
    saved = await card_in(db_session, plan, "apple", source="from_text")
    other_plan = await make_study_plan(
        db_session,
        user_id=plan.user_id,
        target_language="it-IT",
        cefr_level="A1",
        is_active=True,
        duration_weeks=4,
        days_per_week=4,
    )
    await card_in(db_session, other_plan, "libro")
    headers = test_user[1]
    res = await client.get("/api/flashcards/library", headers=headers)
    assert res.status_code == 200
    assert [c["id"] for c in res.json()["items"]] == [saved.id, generated.id]
    for path in ("/vocabulary", "/library?source=from_text"):
        res = await client.get(f"/api/flashcards{path}", headers=headers)
        assert [c["id"] for c in res.json()["items"]] == [saved.id]


async def test_library_filters_new_due_suspended_and_literal_translation_search(
    client, test_user, db_session, plan
):
    new = await card_in(db_session, plan)
    failed = await card_in(db_session, plan, "failed", interval=1, repetitions=0)
    suspended = await card_in(db_session, plan, "suspended", is_suspended=True)
    future = await card_in(
        db_session,
        plan,
        "future",
        interval=6,
        repetitions=2,
        next_review=date.today() + timedelta(days=6),
    )
    future.translation = "100%_literal"
    await db_session.commit()
    for query, expected in [
        ("status=new", {new.id}),
        ("status=due", {new.id, failed.id}),
        ("status=suspended", {suspended.id}),
        ("search=LIBRO", {new.id, failed.id, suspended.id}),
        ("search=%25_", {future.id}),
    ]:
        res = await client.get(f"/api/flashcards/library?{query}", headers=test_user[1])
        assert res.status_code == 200
        assert {c["id"] for c in res.json()["items"]} == expected
    res = await client.get("/api/flashcards/due", headers=test_user[1])
    assert res.json()["total"] == 4
    assert {c["id"] for c in res.json()["due"]} == {new.id, failed.id}


async def test_library_clamps_page_after_deletion(client, test_user, db_session, plan):
    first = await card_in(db_session, plan, "a")
    last = await card_in(db_session, plan, "z")
    response = await client.delete(f"/api/flashcards/{last.id}", headers=test_user[1])
    assert response.status_code == 204
    res = await client.get("/api/flashcards/library?page=2&limit=1", headers=test_user[1])
    assert res.json()["page"] == res.json()["pages"] == 1
    assert res.json()["total"] == 1
    assert res.json()["items"][0]["id"] == first.id


async def test_edit_preserves_schedule_source_and_suspension_on_historical_plan(
    client, test_user, db_session, plan
):
    card = await card_in(
        db_session,
        plan,
        source="from_text",
        is_suspended=True,
        repetitions=4,
        interval=25,
        ease_factor=2.1,
        next_review=date.today() + timedelta(days=25),
    )
    before = scheduling(card)
    plan.is_active = False
    await db_session.commit()
    res = await client.put(
        f"/api/flashcards/{card.id}",
        headers=test_user[1],
        json=content("  Book  ", definition=" New definition "),
    )
    assert res.status_code == 200
    await db_session.refresh(card)
    assert card.word == "Book" and card.definition == "New definition"
    assert card.source == "from_text" and card.is_suspended
    assert scheduling(card) == before
    assert await db_session.scalar(select(func.count()).select_from(Progress)) == 0


async def test_edit_duplicate_check_is_normalized_and_plan_scoped(
    client, test_user, db_session, plan
):
    card = await card_in(db_session, plan)
    await card_in(db_session, plan, "  TAKE\u00a0  off ", is_suspended=True)
    res = await client.put(
        f"/api/flashcards/{card.id}", json=content("take off"), headers=test_user[1]
    )
    assert res.status_code == 409
    assert res.json()["detail"] == "flashcard_duplicate"
    await db_session.refresh(card)
    assert card.word == "book"
    other = await make_study_plan(
        db_session,
        user_id=plan.user_id,
        target_language="it-IT",
        cefr_level="A1",
        is_active=True,
        duration_weeks=4,
        days_per_week=4,
    )
    await card_in(db_session, other, "libro")
    res = await client.put(
        f"/api/flashcards/{card.id}", json=content("libro"), headers=test_user[1]
    )
    assert res.status_code == 200


async def test_legacy_duplicate_does_not_block_content_edits(client, test_user, db_session, plan):
    await card_in(db_session, plan)
    card = await card_in(db_session, plan)
    res = await client.put(f"/api/flashcards/{card.id}", json=content(), headers=test_user[1])
    assert res.status_code == 200


@pytest.mark.parametrize(
    "changes",
    [
        {"word": "  "},
        {"word": "w" * 101},
        {"definition": "\t"},
        {"translation": "t" * 256},
        {"example_sentence": "x" * 10001},
        {"study_plan_id": 999},
        {"repetitions": 20},
        {"source": "from_text"},
        {"definition": None},
    ],
)
async def test_edit_rejects_invalid_content_and_protected_fields(
    client, test_user, db_session, plan, changes
):
    card = await card_in(db_session, plan)
    res = await client.put(
        f"/api/flashcards/{card.id}", json=content(**changes), headers=test_user[1]
    )
    assert res.status_code == 422
    await db_session.refresh(card)
    assert card.definition == "An object with pages"


async def test_suspension_preserves_progress_and_blocks_review_then_reactivates(
    client, test_user, db_session, plan
):
    card = await card_in(db_session, plan, repetitions=4, interval=20, ease_factor=2.2)
    before = scheduling(card)
    path = f"/api/flashcards/{card.id}"
    for _ in range(2):
        res = await client.put(
            f"{path}/suspension", json={"is_suspended": True}, headers=test_user[1]
        )
        assert res.status_code == 200 and res.json()["is_suspended"] is True
    res = await client.post(
        f"{path}/review", json={"quality": 5, "review_id": str(uuid4())}, headers=test_user[1]
    )
    assert res.status_code == 409
    await db_session.refresh(card)
    assert scheduling(card) == before
    assert await db_session.scalar(select(func.count()).select_from(Progress)) == 0
    res = await client.put(f"{path}/suspension", json={"is_suspended": False}, headers=test_user[1])
    assert res.status_code == 200
    await db_session.refresh(card)
    assert scheduling(card) == before
    res = await client.get("/api/flashcards/due", headers=test_user[1])
    assert [item["id"] for item in res.json()["due"]] == [card.id]


async def test_saving_suspended_word_preserves_user_edits_and_does_not_reactivate(
    client, test_user, db_session, plan, monkeypatch
):
    card = await card_in(db_session, plan, is_suspended=True, interval=6, repetitions=2)
    lookup = AsyncMock()
    monkeypatch.setattr("app.routers.flashcards.lookup_word", lookup)
    res = await client.post(
        "/api/flashcards/from-word", json={"word": "BOOK"}, headers=test_user[1]
    )
    assert res.status_code == 200
    assert res.json()["id"] == card.id
    assert res.json()["is_suspended"] is True
    assert res.json()["definition"] == card.definition
    assert res.json()["repetitions"] == 2
    lookup.assert_not_awaited()
    res = await client.post(
        "/api/flashcards/bulk",
        json={
            "flashcards": [content()],
            "study_plan_id": plan.id,
            "target_language": plan.target_language,
        },
        headers=test_user[1],
    )
    assert res.json()["created"] == 0


@pytest.mark.parametrize("quality", [-1, 6, 100, 3.5, "4", True])
async def test_review_rejects_invalid_scores_without_awarding_xp(
    client, test_user, db_session, plan, quality
):
    card = await card_in(db_session, plan)
    before = scheduling(card)
    res = await client.post(
        f"/api/flashcards/{card.id}/review",
        json={"quality": quality, "review_id": str(uuid4())},
        headers=test_user[1],
    )
    assert res.status_code == 422
    await db_session.refresh(card)
    assert scheduling(card) == before
    assert await db_session.scalar(select(func.count()).select_from(Progress)) == 0


async def test_failed_progress_update_does_not_commit_review(
    client, test_user, db_session, plan, monkeypatch
):
    card = await card_in(db_session, plan)
    before = scheduling(card)
    monkeypatch.setattr(
        "app.routers.flashcards.update_daily_progress",
        AsyncMock(side_effect=RuntimeError("failed")),
    )
    with pytest.raises(RuntimeError, match="failed"):
        await client.post(
            f"/api/flashcards/{card.id}/review",
            json={"quality": 4, "review_id": str(uuid4())},
            headers=test_user[1],
        )
    await db_session.rollback()
    await db_session.refresh(card)
    assert scheduling(card) == before


@pytest.mark.parametrize("action", ["edit", "suspend", "delete"])
async def test_management_cannot_access_other_users_cards(
    client, test_user, admin_user, db_session, plan, action
):
    card = await card_in(db_session, plan)
    path = f"/api/flashcards/{card.id}"
    if action == "edit":
        res = await client.put(path, json=content(), headers=admin_user[1])
    elif action == "suspend":
        res = await client.put(
            f"{path}/suspension", json={"is_suspended": True}, headers=admin_user[1]
        )
    else:
        res = await client.delete(path, headers=admin_user[1])
    assert res.status_code == 404
    res = await client.get("/api/flashcards/library", headers=admin_user[1])
    assert res.status_code == 404  # No plan; never falls back to another user's cards.


async def test_preferences_are_account_owned_and_need_no_plan(client, test_user, admin_user):
    res = await client.get("/api/flashcards/preferences", headers=test_user[1])
    assert res.json() == {"back_content": "definition"}
    res = await client.put(
        "/api/flashcards/preferences", json={"back_content": "translation"}, headers=test_user[1]
    )
    assert res.status_code == 200
    res = await client.get("/api/flashcards/preferences", headers=test_user[1])
    assert res.json() == {"back_content": "translation"}
    res = await client.get("/api/flashcards/preferences", headers=admin_user[1])
    assert res.json() == {"back_content": "definition"}
    res = await client.put(
        "/api/flashcards/preferences", json={"back_content": "invalid"}, headers=test_user[1]
    )
    assert res.status_code == 422


@pytest.mark.parametrize("path", ["/library", "/preferences"])
async def test_management_reads_require_auth(client, path):
    assert (await client.get(f"/api/flashcards{path}")).status_code == 401
