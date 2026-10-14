<?php

namespace App\Services\Prompts;

final class LanguagePrompts
{
    public const PLAIN_TEXT_OUTPUT_INSTRUCTION = 'OUTPUT FORMAT: The interface displays text literally and does not render Markdown. '
        .'Use plain text for all learner-facing content, including text inside JSON string values. '
        .'Do not add Markdown formatting: bold or italic markers, headings, blockquotes, '
        .'bullet or numbered list markup, tables, Markdown links, or inline/fenced code. '
        .'Use ordinary sentences and paragraph breaks instead. '
        .'Preserve the requested JSON structure, keys, arrays, data types, and tool-call format. '
        .'Keep required exercise markers such as ___ and meaningful punctuation unchanged.';

    public const JSON_ONLY_INSTRUCTION = 'IMPORTANT: Respond with ONLY a valid JSON object. '
        .'No markdown, no code fences, no extra text. '.self::PLAIN_TEXT_OUTPUT_INSTRUCTION;

    /** @var array<string, string> */
    private const OVERLAYS = [
        'en-US' => "Language-specific guidance:\n- Use American English spelling, vocabulary, punctuation, and idiom consistently.\n- Prefer US forms such as color, center, organize, apartment, elevator, truck, and vacation.\n- Avoid British-only spelling and vocabulary unless explicitly comparing variants.",
        'en-GB' => "Language-specific guidance:\n- Use British English spelling, vocabulary, punctuation, and idiom consistently.\n- Prefer UK forms such as colour, centre, organise, flat, lift, lorry, and holiday.\n- Avoid American-only spelling and vocabulary unless explicitly comparing variants.",
        'es-ES' => "Language-specific guidance:\n- Use Peninsular Spanish from Spain consistently.\n- Prefer Spain usage, including vosotros for informal plural address when appropriate.\n- Avoid voseo and Latin American-only vocabulary unless explicitly comparing variants.\n- Pay close attention to accents, gender, number agreement, and natural Spain Spanish phrasing.",
        'it-IT' => "Language-specific guidance:\n- Use standard Italian as used in Italy consistently.\n- Pay close attention to articles, gender, number agreement, articulated prepositions, and clitic pronouns.\n- Use tu or Lei consistently according to the context and learner level.\n- Avoid strong regionalisms unless explicitly teaching or comparing them.",
        'pt-PT' => "Language-specific guidance:\n- Use European Portuguese from Portugal consistently.\n- Avoid Brazilian Portuguese vocabulary, syntax, and pronoun placement unless explicitly comparing variants.\n- Prefer Portugal usage such as telemóvel, autocarro, pequeno-almoço, and comboio.\n- Pay close attention to European Portuguese clitic placement, contractions, accents, and register.",
        'fr-FR' => "Language-specific guidance:\n- Use standard French from France consistently.\n- Pay close attention to accents, elision, contractions, gender, number agreement, and register.\n- Use tu or vous consistently according to the context and learner level.\n- Avoid Canadian or other regional French variants unless explicitly comparing them.",
        'de-DE' => "Language-specific guidance:\n- Use standard German spelling and vocabulary as used in Germany consistently.\n- Pay close attention to noun capitalization, grammatical gender, cases, adjective endings, and verb position.\n- Use du or Sie consistently according to the context and learner level.\n- Avoid Austrian or Swiss variants unless explicitly comparing them.",
        'ja-JP' => "Language-specific guidance:\n- Use standard Japanese as used in Japan consistently.\n- Use Japanese script naturally: hiragana, katakana, and level-appropriate kanji. Use romaji only as a short support aid for beginners or when explicitly teaching pronunciation.\n- Pay close attention to particles, politeness level, verb forms, counters, and natural word order.\n- Keep register consistent with the learner level; avoid abrupt shifts between plain and polite style unless teaching the contrast.",
        'ko-KR' => "Language-specific guidance:\n- Use standard Korean as used in South Korea consistently.\n- Use Hangul as the primary script. Use romanization only as a short support aid for beginners or when explicitly teaching pronunciation.\n- Pay close attention to particles, speech level, honorifics, verb endings, batchim, and natural Korean phrasing.\n- Avoid North Korean vocabulary, spelling, or usage unless explicitly comparing variants.",
        'zh-CN' => "Language-specific guidance:\n- Use Mainland China Standard Mandarin (Putonghua) consistently.\n- Use simplified Chinese characters. Use pinyin with tone marks only as support for pronunciation or beginner scaffolding, never as the main writing system.\n- Pay close attention to tones, measure words, aspect particles, word order, and natural Mainland usage.\n- Avoid Traditional Chinese, Cantonese, Taiwan, Hong Kong, or Macau variants unless explicitly comparing them.",
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'de' => 'de-DE',
        'fr' => 'fr-FR',
        'es' => 'es-ES',
        'it' => 'it-IT',
        'pt' => 'pt-PT',
        'ja' => 'ja-JP',
        'ko' => 'ko-KR',
        'zh' => 'zh-CN',
    ];

    public static function overlay(string $targetLanguage): string
    {
        $canonical = self::ALIASES[$targetLanguage] ?? $targetLanguage;

        return self::OVERLAYS[$canonical] ?? '';
    }
}
