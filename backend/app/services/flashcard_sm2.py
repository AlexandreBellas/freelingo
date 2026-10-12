import re
from datetime import date, timedelta

from pydantic import field_validator

from app.models.flashcard import Flashcard
from app.schemas.flashcards import (
    FlashcardCreate,
    FlashcardGenerateResponse,
    GeneratedFlashcard,
)
from app.services.language_helpers import get_language_name, get_native_language_name
from app.services.llm_adapter import llm_adapter
from app.services.prompts import flashcards as flashcard_prompts
from app.services.prompts.common import get_language_prompt_overlay
from app.services.prompts.flashcards import (
    build_flashcard_generation_prompt,
    build_word_lookup_prompt,
)

FLASHCARD_GEN_PROMPT = flashcard_prompts.FLASHCARD_GEN_PROMPT
WORD_LOOKUP_PROMPT = flashcard_prompts.WORD_LOOKUP_PROMPT


def sm2_update(card: Flashcard, quality: int) -> Flashcard:
    if quality < 3:
        card.repetitions = 0
        card.interval = 1
    else:
        if card.repetitions == 0:
            card.interval = 1
        elif card.repetitions == 1:
            card.interval = 6
        else:
            card.interval = round(card.interval * card.ease_factor)
        card.repetitions += 1

    card.ease_factor = max(
        1.3,
        card.ease_factor + 0.1 - (5 - quality) * (0.08 + (5 - quality) * 0.02),
    )
    card.next_review = date.today() + timedelta(days=card.interval)
    return card


def _clean_generated_word(value: str) -> str:
    cleaned = value.strip().strip("\"'")
    cleaned = re.sub(r"\s*\([^)]*\)", "", cleaned)
    return re.sub(r"\s+", " ", cleaned).strip()


class _LookupFlashcard(FlashcardCreate):
    @field_validator("word")
    @classmethod
    def clean_lookup_word(cls, value: str) -> str:
        cleaned = _clean_generated_word(value)
        if not cleaned:
            raise ValueError("Lookup word must not be empty after cleaning")
        return cleaned


def _get_lang_hint(target_language: str) -> str:
    return get_language_prompt_overlay(target_language)


async def generate_flashcards(
    topic: str,
    count: int,
    cefr_level: str,
    native_language: str,
    target_language: str = "en-GB",
) -> FlashcardGenerateResponse:
    target_language_name = get_language_name(target_language)
    native_language_name = get_native_language_name(native_language)
    language_prompt_overlay = _get_lang_hint(target_language)
    prompt = build_flashcard_generation_prompt(
        topic=topic,
        count=count,
        cefr_level=cefr_level,
        native_language=native_language_name,
        target_language_name=target_language_name,
        language_prompt_overlay=language_prompt_overlay,
    )

    result = await llm_adapter.structured_output(
        [{"role": "system", "content": prompt}],
        FlashcardGenerateResponse,
    )
    result.flashcards = [
        GeneratedFlashcard(
            word=_clean_generated_word(card.word),
            definition=card.definition,
            example_sentence=card.example_sentence,
            translation=card.translation,
        )
        for card in result.flashcards
    ]
    return result


async def lookup_word(
    word: str,
    context: str,
    cefr_level: str,
    native_language: str,
    target_language: str = "en-GB",
) -> FlashcardCreate:
    target_language_name = get_language_name(target_language)
    native_language_name = get_native_language_name(native_language)
    language_prompt_overlay = _get_lang_hint(target_language)
    prompt = build_word_lookup_prompt(
        word=word,
        context=context or word,
        cefr_level=cefr_level,
        native_language=native_language_name,
        target_language_name=target_language_name,
        language_prompt_overlay=language_prompt_overlay,
    )
    result = await llm_adapter.structured_output(
        [{"role": "system", "content": prompt}],
        _LookupFlashcard,
    )
    return result
