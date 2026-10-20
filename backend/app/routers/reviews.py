from __future__ import annotations

import asyncio
from typing import Literal

from fastapi import APIRouter, Depends, Query, Request, Response, status
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.database import get_db
from app.core.deps import get_current_user, require_admin
from app.core.limiter import limiter
from app.models.review import Review
from app.models.user import User
from app.schemas.review import (
    PaginatedReviewsResponse,
    ReviewAdminOut,
    ReviewApprovalUpdate,
    ReviewCreate,
    ReviewMeResponse,
    ReviewPublicOut,
    ReviewPublicSummary,
    ReviewUpdate,
)
from app.services import email_service
from app.services.review_service import (
    create_review,
    delete_review,
    delete_user_review,
    get_user_review,
    update_review_approval,
    update_user_review,
)
from app.utils.pagination import paginate

router = APIRouter(tags=["reviews"])


@router.get("/api/reviews/me", response_model=ReviewMeResponse)
@limiter.limit("60/minute")
async def get_my_review(
    request: Request,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
) -> ReviewMeResponse:
    review = await get_user_review(db, current_user.id)
    return ReviewMeResponse(has_review=review is not None, review=review)


@router.post("/api/reviews", response_model=ReviewAdminOut, status_code=status.HTTP_201_CREATED)
@limiter.limit("5/hour")
async def create_my_review(
    request: Request,
    data: ReviewCreate,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
) -> Review:
    review = await create_review(db, current_user, rating=data.rating, comment=data.comment)
    admin_locale = await db.scalar(
        select(User.native_language).where(User.role == "admin").order_by(User.id.asc()).limit(1)
    )
    asyncio.create_task(
        email_service.send_review_notification(
            user_display_name=review.user_display_name,
            rating=review.rating,
            comment=review.comment,
            target_language=review.target_language,
            review_id=review.id,
            locale=admin_locale or "en",
        )
    )
    return review


@router.patch("/api/reviews/me", response_model=ReviewAdminOut)
@limiter.limit("10/hour")
async def patch_my_review(
    request: Request,
    data: ReviewUpdate,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
) -> Review:
    return await update_user_review(db, current_user, rating=data.rating, comment=data.comment)


@router.delete("/api/reviews/me", status_code=status.HTTP_204_NO_CONTENT)
@limiter.limit("10/hour")
async def delete_my_review(
    request: Request,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
) -> Response:
    await delete_user_review(db, current_user.id)
    return Response(status_code=status.HTTP_204_NO_CONTENT)


@router.get("/api/reviews/public", response_model=list[ReviewPublicOut])
@limiter.limit("60/minute")
async def list_public_reviews(
    request: Request,
    limit: int = Query(default=50, ge=1, le=50),
    db: AsyncSession = Depends(get_db),
) -> list[Review]:
    result = await db.execute(
        select(Review)
        .where(
            Review.is_approved.is_(True),
            Review.rating >= 4,
            Review.comment.is_not(None),
            Review.comment != "",
        )
        .order_by(Review.created_at.desc())
        .limit(limit)
    )
    return list(result.scalars().all())


@router.get("/api/reviews/public/summary", response_model=ReviewPublicSummary)
@limiter.limit("60/minute")
async def get_public_review_summary(
    request: Request,
    db: AsyncSession = Depends(get_db),
) -> ReviewPublicSummary:
    result = await db.execute(
        select(func.count(Review.id), func.avg(Review.rating)).where(Review.is_approved.is_(True))
    )
    total, average_rating = result.one()
    return ReviewPublicSummary(total=total, average_rating=average_rating)


@router.get("/api/admin/reviews", response_model=PaginatedReviewsResponse)
@limiter.limit("60/minute")
async def list_admin_reviews(
    request: Request,
    is_approved: bool | None = Query(default=None),
    rating: int | None = Query(default=None, ge=1, le=5),
    target_language: str | None = Query(default=None, max_length=10),
    order: Literal["asc", "desc"] = Query(default="desc"),
    skip: int = Query(default=0, ge=0),
    limit: int = Query(default=10, ge=1, le=100),
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
) -> PaginatedReviewsResponse:
    stmt = select(Review)
    if is_approved is not None:
        stmt = stmt.where(Review.is_approved.is_(is_approved))
    if rating is not None:
        stmt = stmt.where(Review.rating == rating)
    if target_language:
        stmt = stmt.where(Review.target_language == target_language)
    stmt = stmt.order_by(Review.created_at.asc() if order == "asc" else Review.created_at.desc())
    reviews, total = await paginate(db, stmt, skip, limit)
    return PaginatedReviewsResponse(items=reviews, total=total, skip=skip, limit=limit)


@router.patch("/api/admin/reviews/{review_id}", response_model=ReviewAdminOut)
@limiter.limit("60/minute")
async def patch_admin_review(
    request: Request,
    review_id: int,
    data: ReviewApprovalUpdate,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
) -> Review:
    return await update_review_approval(db, review_id, is_approved=data.is_approved)


@router.delete("/api/admin/reviews/{review_id}", status_code=status.HTTP_204_NO_CONTENT)
@limiter.limit("60/minute")
async def delete_admin_review(
    request: Request,
    review_id: int,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
) -> Response:
    await delete_review(db, review_id)
    return Response(status_code=status.HTTP_204_NO_CONTENT)
