<?php

namespace App\Services\Practice;

use Illuminate\Support\Carbon;

final class Languages
{
    /** @var array<string, array<string, string>> */
    private const INFO = [
        'en-US' => ['name' => 'English (US)', 'self_name' => 'English (US)', 'iso639' => 'en', 'flag' => '🇺🇸'],
        'en-GB' => ['name' => 'English (UK)', 'self_name' => 'English (UK)', 'iso639' => 'en', 'flag' => '🇬🇧'],
        'de-DE' => ['name' => 'German', 'self_name' => 'Deutsch', 'iso639' => 'de', 'flag' => '🇩🇪'],
        'es-ES' => ['name' => 'Spanish (Spain)', 'self_name' => 'Español (España)', 'iso639' => 'es', 'flag' => '🇪🇸'],
        'fr-FR' => ['name' => 'French', 'self_name' => 'Français', 'iso639' => 'fr', 'flag' => '🇫🇷'],
        'it-IT' => ['name' => 'Italian', 'self_name' => 'Italiano', 'iso639' => 'it', 'flag' => '🇮🇹'],
        'pt-PT' => ['name' => 'European Portuguese', 'self_name' => 'Português (Portugal)', 'iso639' => 'pt', 'flag' => '🇵🇹'],
        'ja-JP' => ['name' => 'Japanese', 'self_name' => '日本語', 'iso639' => 'ja', 'flag' => '🇯🇵'],
        'ko-KR' => ['name' => 'Korean (South Korea)', 'self_name' => '한국어', 'iso639' => 'ko', 'flag' => '🇰🇷'],
        'zh-CN' => ['name' => 'Chinese (Mainland China)', 'self_name' => '中文（中国）', 'iso639' => 'zh', 'flag' => '🇨🇳'],
    ];

    /** @var array<string, array<string, mixed>> */
    private const CAPABILITIES = [
        'en-US' => ['script' => 'latin', 'romanization' => '', 'uses_word_spacing' => true, 'reading_length_unit' => 'words'],
        'en-GB' => ['script' => 'latin', 'romanization' => '', 'uses_word_spacing' => true, 'reading_length_unit' => 'words'],
        'de-DE' => ['script' => 'latin', 'romanization' => '', 'uses_word_spacing' => true, 'reading_length_unit' => 'words'],
        'es-ES' => ['script' => 'latin', 'romanization' => '', 'uses_word_spacing' => true, 'reading_length_unit' => 'words'],
        'fr-FR' => ['script' => 'latin', 'romanization' => '', 'uses_word_spacing' => true, 'reading_length_unit' => 'words'],
        'it-IT' => ['script' => 'latin', 'romanization' => '', 'uses_word_spacing' => true, 'reading_length_unit' => 'words'],
        'pt-PT' => ['script' => 'latin', 'romanization' => '', 'uses_word_spacing' => true, 'reading_length_unit' => 'words'],
        'ja-JP' => ['script' => 'hiragana-katakana-kanji', 'romanization' => 'romaji', 'uses_word_spacing' => false, 'reading_length_unit' => 'characters'],
        'ko-KR' => ['script' => 'hangul', 'romanization' => 'revised-romanization', 'uses_word_spacing' => true, 'reading_length_unit' => 'words'],
        'zh-CN' => ['script' => 'simplified-hanzi', 'romanization' => 'pinyin', 'uses_word_spacing' => false, 'reading_length_unit' => 'characters'],
    ];

    /** @var array<string, string> */
    private const CAPABILITY_ALIASES = [
        'de' => 'de-DE', 'es' => 'es-ES', 'fr' => 'fr-FR', 'it' => 'it-IT',
        'pt' => 'pt-PT', 'ja' => 'ja-JP', 'ko' => 'ko-KR', 'zh' => 'zh-CN',
    ];

    /** @var array<string, string> */
    private const NATIVE_NAMES = [
        'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'pt' => 'Portuguese',
        'de' => 'German', 'it' => 'Italian', 'pl' => 'Polish', 'nl' => 'Dutch',
        'ro' => 'Romanian', 'ru' => 'Russian', 'tr' => 'Turkish', 'sv' => 'Swedish',
        'da' => 'Danish', 'fi' => 'Finnish', 'hr' => 'Croatian',
    ];

    /** @var array<string, string> */
    private const VOICE_TITLES = [
        'es' => 'Sesión de voz', 'fr' => 'Session vocale', 'pt' => 'Sessão de voz',
        'de' => 'Sprachsitzung', 'it' => 'Sessione vocale', 'pl' => 'Sesja głosowa',
        'nl' => 'Spraaksessie', 'ro' => 'Sesiune vocală', 'ru' => 'Голосовая сессия',
        'tr' => 'Sesli oturum', 'sv' => 'Röstsamtal', 'da' => 'Stemmesamtale',
        'fi' => 'Äänikeskustelu', 'hr' => 'Glasovni razgovor',
    ];

    /** @var array<string, string> */
    private const LESSON_PRACTICE = [
        'en' => 'Practice', 'es' => 'Práctica', 'fr' => 'Pratique', 'pt' => 'Prática',
        'de' => 'Übung', 'it' => 'Pratica', 'pl' => 'Ćwiczenie', 'nl' => 'Oefening',
        'ro' => 'Practică', 'ru' => 'Практика', 'tr' => 'Pratik', 'sv' => 'Övning',
        'da' => 'Øvelse', 'fi' => 'Harjoitus', 'hr' => 'Vježba',
    ];

    /** @var array<string, list<string>> */
    private const MONTHS = [
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
        'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'pt' => ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'],
        'de' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
        'it' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
        'pl' => ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'],
        'nl' => ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
        'ro' => ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'],
        'ru' => ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'],
        'tr' => ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'],
        'sv' => ['januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'],
        'da' => ['januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'december'],
        'fi' => ['tammikuuta', 'helmikuuta', 'maaliskuuta', 'huhtikuuta', 'toukokuuta', 'kesäkuuta', 'heinäkuuta', 'elokuuta', 'syyskuuta', 'lokakuuta', 'marraskuuta', 'joulukuuta'],
        'hr' => ['siječnja', 'veljače', 'ožujka', 'travnja', 'svibnja', 'lipnja', 'srpnja', 'kolovoza', 'rujna', 'listopada', 'studenoga', 'prosinca'],
    ];

    public static function name(string $targetLanguage): string
    {
        return self::INFO[$targetLanguage]['name'] ?? $targetLanguage;
    }

    public static function selfName(string $targetLanguage): string
    {
        return self::INFO[$targetLanguage]['self_name'] ?? $targetLanguage;
    }

    public static function iso639(string $targetLanguage): string
    {
        return self::INFO[$targetLanguage]['iso639'] ?? strtolower(explode('-', $targetLanguage)[0]);
    }

    public static function flag(string $targetLanguage): string
    {
        return self::INFO[$targetLanguage]['flag'] ?? '';
    }

    public static function nativeName(string $nativeLanguage): string
    {
        return self::NATIVE_NAMES[$nativeLanguage] ?? $nativeLanguage;
    }

    /** @return array<string, mixed> */
    private static function capability(string $targetLanguage): array
    {
        $canonical = self::CAPABILITY_ALIASES[$targetLanguage] ?? $targetLanguage;
        if (! isset(self::CAPABILITIES[$canonical])) {
            $iso = strtolower(explode('-', $targetLanguage)[0]);
            $canonical = self::CAPABILITY_ALIASES[$iso] ?? $canonical;
        }

        return self::CAPABILITIES[$canonical] ?? self::CAPABILITIES['en-GB'];
    }

    public static function script(string $targetLanguage): string
    {
        return (string) self::capability($targetLanguage)['script'];
    }

    public static function romanization(string $targetLanguage): string
    {
        return (string) self::capability($targetLanguage)['romanization'];
    }

    public static function usesWordSpacing(string $targetLanguage): bool
    {
        return (bool) self::capability($targetLanguage)['uses_word_spacing'];
    }

    public static function readingLengthUnit(string $targetLanguage): string
    {
        return (string) self::capability($targetLanguage)['reading_length_unit'];
    }

    public static function comprehensionLengthGuidance(string $targetLanguage, int $baseWordCount): string
    {
        if (self::readingLengthUnit($targetLanguage) === 'characters') {
            return ($baseWordCount * 2).'–'.($baseWordCount * 3).' characters';
        }

        return $baseWordCount.' words';
    }

    public static function lessonPracticeTitle(string $nativeLanguage, string $lessonTitle): string
    {
        $label = self::LESSON_PRACTICE[$nativeLanguage] ?? 'Practice';

        return mb_substr($label.': '.$lessonTitle, 0, 200);
    }

    public static function voiceSessionTitle(string $nativeLanguage, ?Carbon $now = null): string
    {
        $label = self::VOICE_TITLES[$nativeLanguage] ?? 'Voice session';
        $now = ($now ?? Carbon::now('UTC'))->utc();
        $months = self::MONTHS[$nativeLanguage] ?? null;
        if ($months) {
            $monthName = $months[$now->month - 1];
            if (in_array($nativeLanguage, ['es', 'pt'], true)) {
                return "{$label} — {$now->day} de {$monthName} de {$now->year}";
            }
            if (in_array($nativeLanguage, ['de', 'da', 'fi'], true)) {
                return "{$label} — {$now->day}. {$monthName} {$now->year}";
            }
            if ($nativeLanguage === 'hr') {
                return "{$label} — {$now->day}. {$monthName} {$now->year}.";
            }

            return "{$label} — {$now->day} {$monthName} {$now->year}";
        }

        return $label.' — '.$now->format('F d, Y');
    }
}
