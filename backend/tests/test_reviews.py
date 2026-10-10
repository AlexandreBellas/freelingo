from __future__ import annotations

from datetime import UTC, datetime

import pytest


def _now() -> datetime:
    return datetime.now(UTC).replace(tzinfo=None)


async def _add_review(
    db_session,
    *,
    user_id: int,
    display_name: str = "Reviewer",
    target_language: str = "en-US",
    rating: int = 5,
    comment: str | None = "Great app",
    is_approved: bool = False,
):
    from app.models.review import Review

    now = _now()
    review = Review(
        user_id=user_id,
        user_display_name=display_name,
        target_language=target_language,
        rating=rating,
        comment=comment,
        is_approved=is_approved,
        created_at=now,
        updated_at=now,
    )
    db_session.add(review)
    await db_session.commit()
    await db_session.refresh(review)
    return review


@pytest.mark.asyncio
async def test_create_review_valid(client, test_user_with_plan):
    user, headers = test_user_with_plan

    response = await client.post(
        "/api/reviews",
        json={"rating": 5, "comment": "FreeLingo helps me practice every day."},
        headers=headers,
    )

    assert response.status_code == 201
    data = response.json()
    assert data["user_id"] == user.id
    assert data["user_display_name"] == "Test User"
    assert data["target_language"] == "en-US"
    assert data["rating"] == 5
    assert data["comment"] == "FreeLingo helps me practice every day."
    assert data["is_approved"] is False


@pytest.mark.asyncio
async def test_create_review_sends_admin_email(client, test_user_with_plan, admin_user):
    from unittest.mock import AsyncMock, patch

    _, headers = test_user_with_plan
    admin, _ = admin_user
    admin.native_language = "es"

    with patch(
        "app.routers.reviews.email_service.send_review_notification",
        new_callable=AsyncMock,
    ) as mock_notify:
        response = await client.post(
            "/api/reviews",
            json={"rating": 5, "comment": "Excellent tutor."},
            headers=headers,
        )

    assert response.status_code == 201
    mock_notify.assert_awaited_once()
    call_kwargs = mock_notify.call_args.kwargs
    assert call_kwargs["user_display_name"] == "Test User"
    assert call_kwargs["rating"] == 5
    assert call_kwargs["comment"] == "Excellent tutor."
    assert call_kwargs["target_language"] == "en-US"
    assert call_kwargs["locale"] == "es"


@pytest.mark.asyncio
async def test_create_review_requires_rating(client, test_user_with_plan):
    _, headers = test_user_with_plan

    response = await client.post(
        "/api/reviews", json={"comment": "Missing rating"}, headers=headers
    )

    assert response.status_code == 422


@pytest.mark.asyncio
async def test_create_review_rejects_rating_below_one(client, test_user_with_plan):
    _, headers = test_user_with_plan

    response = await client.post("/api/reviews", json={"rating": 0}, headers=headers)

    assert response.status_code == 422


@pytest.mark.asyncio
async def test_create_review_rejects_rating_above_five(client, test_user_with_plan):
    _, headers = test_user_with_plan

    response = await client.post("/api/reviews", json={"rating": 6}, headers=headers)

    assert response.status_code == 422


@pytest.mark.asyncio
async def test_create_review_allows_missing_comment(client, test_user_with_plan):
    _, headers = test_user_with_plan

    response = await client.post("/api/reviews", json={"rating": 4}, headers=headers)

    assert response.status_code == 201
    assert response.json()["comment"] is None


@pytest.mark.asyncio
async def test_create_review_allows_empty_comment(client, test_user_with_plan):
    _, headers = test_user_with_plan

    response = await client.post(
        "/api/reviews", json={"rating": 4, "comment": "   "}, headers=headers
    )

    assert response.status_code == 201
    assert response.json()["comment"] is None


@pytest.mark.asyncio
async def test_create_review_rejects_duplicate(client, test_user_with_plan):
    _, headers = test_user_with_plan

    first = await client.post("/api/reviews", json={"rating": 5}, headers=headers)
    second = await client.post("/api/reviews", json={"rating": 4}, headers=headers)

    assert first.status_code == 201
    assert second.status_code == 409
    assert second.json()["detail"] == "review_already_exists"


@pytest.mark.asyncio
async def test_get_my_review_empty(client, test_user):
    _, headers = test_user

    response = await client.get("/api/reviews/me", headers=headers)

    assert response.status_code == 200
    assert response.json() == {"has_review": False, "review": None}


@pytest.mark.asyncio
async def test_get_my_review_existing(client, test_user, db_session):
    user, headers = test_user
    await _add_review(db_session, user_id=user.id, display_name=user.display_name, rating=5)

    response = await client.get("/api/reviews/me", headers=headers)

    assert response.status_code == 200
    data = response.json()
    assert data["has_review"] is True
    assert data["review"]["rating"] == 5
    assert data["review"]["user_display_name"] == user.display_name


@pytest.mark.asyncio
async def test_update_my_review(client, test_user, db_session):
    user, headers = test_user
    review = await _add_review(
        db_session,
        user_id=user.id,
        display_name=user.display_name,
        rating=5,
        comment="Original",
        is_approved=True,
    )

    response = await client.patch(
        "/api/reviews/me",
        json={"rating": 4, "comment": "Updated review"},
        headers=headers,
    )

    assert response.status_code == 200
    data = response.json()
    assert data["id"] == review.id
    assert data["rating"] == 4
    assert data["comment"] == "Updated review"
    assert data["is_approved"] is False


@pytest.mark.asyncio
async def test_update_my_review_does_not_send_admin_email(client, test_user, db_session):
    from unittest.mock import AsyncMock, patch

    user, headers = test_user
    await _add_review(
        db_session,
        user_id=user.id,
        display_name=user.display_name,
        rating=5,
        comment="Original",
        is_approved=True,
    )

    with patch(
        "app.routers.reviews.email_service.send_review_notification",
        new_callable=AsyncMock,
    ) as mock_notify:
        response = await client.patch(
            "/api/reviews/me",
            json={"rating": 4, "comment": "Updated review"},
            headers=headers,
        )

    assert response.status_code == 200
    mock_notify.assert_not_awaited()


@pytest.mark.asyncio
async def test_update_my_review_requires_existing_review(client, test_user):
    _, headers = test_user

    response = await client.patch(
        "/api/reviews/me", json={"rating": 4, "comment": "Updated"}, headers=headers
    )

    assert response.status_code == 404
    assert response.json()["detail"] == "review_not_found"


@pytest.mark.asyncio
async def test_delete_my_review(client, test_user, db_session):
    from app.models.review import Review

    user, headers = test_user
    review = await _add_review(db_session, user_id=user.id, display_name=user.display_name)

    response = await client.delete("/api/reviews/me", headers=headers)

    assert response.status_code == 204
    assert await db_session.get(Review, review.id) is None


@pytest.mark.asyncio
async def test_delete_my_review_requires_existing_review(client, test_user):
    _, headers = test_user

    response = await client.delete("/api/reviews/me", headers=headers)

    assert response.status_code == 404
    assert response.json()["detail"] == "review_not_found"


@pytest.mark.asyncio
async def test_public_reviews_exclude_unapproved(client, test_user, db_session):
    user, _ = test_user
    await _add_review(db_session, user_id=user.id, rating=5, is_approved=False)

    response = await client.get("/api/reviews/public")

    assert response.status_code == 200
    assert response.json() == []


@pytest.mark.asyncio
async def test_public_reviews_exclude_low_rating(client, test_user, db_session):
    user, _ = test_user
    await _add_review(db_session, user_id=user.id, rating=3, is_approved=True)

    response = await client.get("/api/reviews/public")

    assert response.status_code == 200
    assert response.json() == []


@pytest.mark.asyncio
async def test_public_reviews_include_approved_positive_rating(client, test_user, db_session):
    user, _ = test_user
    await _add_review(
        db_session,
        user_id=user.id,
        display_name="Landing User",
        target_language="fr-FR",
        rating=4,
        comment="Great app",
        is_approved=True,
    )

    response = await client.get("/api/reviews/public")

    assert response.status_code == 200
    data = response.json()
    assert len(data) == 1
    assert data[0]["user_display_name"] == "Landing User"
    assert data[0]["target_language"] == "fr-FR"
    assert data[0]["rating"] == 4
    assert data[0]["comment"] == "Great app"
    assert "is_approved" not in data[0]
    assert "user_id" not in data[0]


@pytest.mark.asyncio
async def test_admin_list_reviews(client, admin_user, test_user, db_session):
    _, admin_headers = admin_user
    user, _ = test_user
    await _add_review(db_session, user_id=user.id, rating=5, is_approved=False)

    response = await client.get("/api/admin/reviews", headers=admin_headers)

    assert response.status_code == 200
    data = response.json()
    assert data["total"] == 1
    assert data["items"][0]["rating"] == 5
    assert data["items"][0]["is_approved"] is False


@pytest.mark.asyncio
async def test_admin_approve_and_unapprove_review(client, admin_user, test_user, db_session):
    _, admin_headers = admin_user
    user, _ = test_user
    review = await _add_review(db_session, user_id=user.id, rating=5, is_approved=False)

    approve = await client.patch(
        f"/api/admin/reviews/{review.id}",
        json={"is_approved": True},
        headers=admin_headers,
    )
    unapprove = await client.patch(
        f"/api/admin/reviews/{review.id}",
        json={"is_approved": False},
        headers=admin_headers,
    )

    assert approve.status_code == 200
    assert approve.json()["is_approved"] is True
    assert unapprove.status_code == 200
    assert unapprove.json()["is_approved"] is False


@pytest.mark.asyncio
async def test_admin_delete_review(client, admin_user, test_user, db_session):
    from app.models.review import Review

    _, admin_headers = admin_user
    user, _ = test_user
    review = await _add_review(db_session, user_id=user.id, rating=5, is_approved=False)

    response = await client.delete(f"/api/admin/reviews/{review.id}", headers=admin_headers)

    assert response.status_code == 204
    assert await db_session.get(Review, review.id) is None


@pytest.mark.asyncio
async def test_non_admin_cannot_access_admin_reviews(client, test_user):
    _, headers = test_user

    response = await client.get("/api/admin/reviews", headers=headers)

    assert response.status_code == 403
