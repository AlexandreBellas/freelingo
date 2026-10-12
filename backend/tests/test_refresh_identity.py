"""A shared refresh cookie cannot retarget a known tab's account context."""

from datetime import UTC, datetime, timedelta

import jwt
import pytest

from app.core.config import settings
from app.core.security import decode_access_token


def expired_access(user, **overrides):
    return jwt.encode(
        {
            "sub": str(user.id),
            "role": user.role,
            "exp": datetime.now(UTC) - timedelta(minutes=1),
            **overrides,
        },
        settings.SECRET_KEY,
        algorithm="HS256",
    )


async def seed_cookie(client, redis, user):
    token = "cookie-for-second-tab"
    await redis.setex(f"refresh:{token}", 3600, str(user.id))
    client.cookies.set("refresh_token", token)
    return token


@pytest.mark.parametrize("context", ["bearer", "profile", "both", "conflicting-profile"])
async def test_cross_account_context_is_rejected_without_consuming_or_replacing_cookie(
    client, test_user, admin_user, mock_redis, context
):
    first, second = test_user[0], admin_user[0]
    cookie = await seed_cookie(client, mock_redis, second)
    headers = {}
    if context in ("bearer", "both", "conflicting-profile"):
        headers["Authorization"] = (
            f"Bearer {expired_access(second if context == 'conflicting-profile' else first)}"
        )
    if context in ("profile", "both", "conflicting-profile"):
        headers["X-Expected-User-ID"] = str(first.id)
    response = await client.post("/api/auth/refresh", headers=headers)
    assert response.status_code == 409
    assert response.json()["detail"] == "auth_context_changed"
    assert "set-cookie" not in response.headers
    assert await mock_redis.get(f"refresh:{cookie}") == str(second.id)
    # The other tab can still rotate its cookie normally.
    current = await client.post("/api/auth/refresh", headers={"X-Expected-User-ID": str(second.id)})
    assert current.status_code == 200
    assert decode_access_token(current.json()["access_token"])["sub"] == str(second.id)
    assert await mock_redis.get(f"refresh:{cookie}") is None


async def test_expired_context_allows_only_cookie_authorized_same_account_rotation(
    client, test_user, mock_redis
):
    user = test_user[0]
    cookie = await seed_cookie(client, mock_redis, user)
    headers = {
        "Authorization": f"Bearer {expired_access(user)}",
        "X-Expected-User-ID": str(user.id),
    }
    assert (await client.get("/api/auth/me", headers=headers)).status_code == 401
    renewed = await client.post("/api/auth/refresh", headers=headers)
    assert renewed.status_code == 200
    assert decode_access_token(renewed.json()["access_token"])["sub"] == str(user.id)
    assert await mock_redis.get(f"refresh:{cookie}") is None
    replacement = renewed.cookies.get("refresh_token")
    assert replacement and replacement != cookie
    assert await mock_redis.get(f"refresh:{replacement}") == str(user.id)


@pytest.mark.parametrize("context", ["malformed", "wrong-signature", "missing-sub", "basic"])
async def test_invalid_bearer_context_never_consumes_cookie(client, test_user, mock_redis, context):
    user = test_user[0]
    cookie = await seed_cookie(client, mock_redis, user)
    value = "Bearer invalid"
    if context == "wrong-signature":
        value = "Bearer " + jwt.encode(
            {"sub": str(user.id), "exp": 1}, "not-the-signing-key", algorithm="HS256"
        )
    elif context == "missing-sub":
        value = "Bearer " + jwt.encode({"exp": 1}, settings.SECRET_KEY, algorithm="HS256")
    elif context == "basic":
        value = "Basic " + expired_access(user)
    response = await client.post("/api/auth/refresh", headers={"Authorization": value})
    assert response.status_code == 401
    assert "set-cookie" not in response.headers
    assert await mock_redis.get(f"refresh:{cookie}") == str(user.id)


async def test_expectation_does_not_replace_the_required_cookie(client, test_user):
    response = await client.post(
        "/api/auth/refresh", headers={**test_user[1], "X-Expected-User-ID": str(test_user[0].id)}
    )
    assert response.status_code == 401


@pytest.mark.parametrize("value", ["0", "-1", "other", "2147483648"])
async def test_invalid_profile_context_is_rejected_before_rotation(
    client, test_user, mock_redis, value
):
    cookie = await seed_cookie(client, mock_redis, test_user[0])
    response = await client.post("/api/auth/refresh", headers={"X-Expected-User-ID": value})
    assert response.status_code == 422
    assert await mock_redis.get(f"refresh:{cookie}") == str(test_user[0].id)
