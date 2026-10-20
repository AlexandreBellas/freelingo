from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, Field, field_serializer, field_validator


class ReviewCreate(BaseModel):
    rating: int = Field(..., ge=1, le=5)
    comment: str | None = Field(default=None, max_length=2000)

    @field_validator("comment")
    @classmethod
    def normalize_comment(cls, value: str | None) -> str | None:
        if value is None:
            return None
        stripped = value.strip()
        return stripped or None


class ReviewUpdate(ReviewCreate):
    pass


class ReviewApprovalUpdate(BaseModel):
    is_approved: bool


class ReviewPublicOut(BaseModel):
    id: int
    user_display_name: str
    target_language: str
    rating: int
    comment: str | None
    created_at: datetime

    model_config = {"from_attributes": True}

    @field_serializer("created_at")
    def serialize_created_at(self, value: datetime, _info: object) -> str:
        return value.isoformat()


class ReviewPublicSummary(BaseModel):
    total: int = Field(ge=0)
    average_rating: float | None = Field(ge=1, le=5)


class ReviewAdminOut(ReviewPublicOut):
    user_id: int
    is_approved: bool
    updated_at: datetime

    @field_serializer("updated_at")
    def serialize_updated_at(self, value: datetime, _info: object) -> str:
        return value.isoformat()


class ReviewMeResponse(BaseModel):
    has_review: bool
    review: ReviewAdminOut | None


class PaginatedReviewsResponse(BaseModel):
    items: list[ReviewAdminOut]
    total: int
    skip: int
    limit: int
