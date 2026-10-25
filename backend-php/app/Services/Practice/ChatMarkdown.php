<?php

namespace App\Services\Practice;

/**
 * CommonMark speech extraction matching chat_markdown.chat_markdown_to_speech.
 */
final class ChatMarkdown
{
    private const ENDINGS = ".!?;:…。！？；：";

    private const CLOSING = "\"'”’»」』)]}）】";

    public static function toSpeech(string $text, ?string $language = null): string
    {
        $prefix = explode('-', $language ?? '')[0];
        $fullStop = in_array($prefix, ['ja', 'zh'], true) ? '。' : '.';
        $parts = [];
        $previousWasList = false;
        foreach (self::blocks($text) as $block) {
            $content = trim($block['text']);
            if ($content === '') {
                continue;
            }
            $inList = $block['list'];
            if ($inList) {
                $ending = rtrim($content, self::CLOSING);
                $last = $ending === '' ? '' : mb_substr($ending, -1);
                if ($last !== '' && ! str_contains(self::ENDINGS, $last)) {
                    $content .= $fullStop;
                }
            }
            if ($parts !== []) {
                $parts[] = ($inList && $previousWasList) ? "\n" : "\n\n";
            }
            $parts[] = $content;
            $previousWasList = $inList;
        }

        return implode('', $parts);
    }

    /**
     * @return list<array{list: bool, text: string}>
     */
    private static function blocks(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $blocks = [];
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;

                continue;
            }
            if (preg_match('/^ {0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $i++;

                continue;
            }
            if (preg_match('/^ {0,3}```/', $line)) {
                $i++;
                $body = [];
                while ($i < $count && ! preg_match('/^ {0,3}```/', $lines[$i])) {
                    $body[] = $lines[$i];
                    $i++;
                }
                if ($i < $count) {
                    $i++;
                }
                $blocks[] = ['list' => false, 'text' => rtrim(implode("\n", $body))];

                continue;
            }
            if (preg_match('/^ {0,3}<script\b/i', $line)) {
                while ($i < $count && ! str_contains(strtolower($lines[$i]), '</script>')) {
                    $i++;
                }
                if ($i < $count) {
                    $i++;
                }

                continue;
            }
            if (preg_match('/^ {0,3}\[[^\]]+\]:\s+\S+/', $line)) {
                $i++;

                continue;
            }
            if (preg_match('/^ {0,3}#{1,6}\s+(.*)$/', $line, $match)) {
                $blocks[] = ['list' => false, 'text' => self::inline($match[1])];
                $i++;

                continue;
            }
            if (preg_match('/^ {0,3}>\s?(.*)$/', $line, $match)) {
                $buf = [$match[1]];
                $i++;
                while ($i < $count && preg_match('/^ {0,3}>\s?(.*)$/', $lines[$i], $match)) {
                    $buf[] = $match[1];
                    $i++;
                }
                $blocks[] = ['list' => false, 'text' => self::inline(self::joinLines($buf))];

                continue;
            }
            if (self::listMarker($line) !== null) {
                $item = [self::listMarker($line)];
                $i++;
                while ($i < $count && $lines[$i] !== '' && self::listMarker($lines[$i]) === null && ! self::blockStart($lines[$i])) {
                    $item[] = ltrim($lines[$i]);
                    $i++;
                }
                $blocks[] = ['list' => true, 'text' => self::inline(self::joinLines($item))];

                continue;
            }
            $buf = [$line];
            $i++;
            while ($i < $count && $lines[$i] !== '' && ! self::blockStart($lines[$i]) && self::listMarker($lines[$i]) === null) {
                $buf[] = $lines[$i];
                $i++;
            }
            $blocks[] = ['list' => false, 'text' => self::inline(self::joinLines($buf))];
        }

        return $blocks;
    }

    private static function blockStart(string $line): bool
    {
        return (bool) preg_match('/^ {0,3}(?:```|#{1,6}\s|>|<{1,3}script\b|\[[^\]]+\]:\s)/i', $line)
            || (bool) preg_match('/^ {0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/', $line);
    }

    private static function listMarker(string $line): ?string
    {
        if (preg_match('/^ {0,3}(?:[-+*]|\d+[.)])\s+(.*)$/', $line, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function joinLines(array $lines): string
    {
        $out = '';
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                $out = $line;

                continue;
            }
            $hard = str_ends_with($out, '  ') || str_ends_with($out, '\\');
            if ($hard) {
                $out = rtrim($out);
                if (str_ends_with($out, '\\')) {
                    $out = substr($out, 0, -1);
                }
            }
            $out .= "\n".$line;
        }

        return $out;
    }

    private static function inline(string $text): string
    {
        $protected = [];
        $protect = function (string $value) use (&$protected): string {
            $protected[] = $value;

            return "\u{E004}".(count($protected) - 1)."\u{E005}";
        };
        $image = '/!\[((?:\\\\.|[^\]\[])*)\](?:\([^)\n]*\)|\[[^\]]*\])/s';
        $link = '/\[((?:\\\\.|[^\]\[])*)\](?:\([^)\n]*\)|\[[^\]]*\])/s';
        $guard = 0;
        while ($guard++ < 20 && preg_match($image, $text)) {
            $next = preg_replace_callback($image, function (array $match) use ($protect): string {
                return $protect(self::inline(str_replace(["\\\n", "\\\r"], "\n", $match[1])));
            }, $text) ?? $text;
            if ($next === $text) {
                break;
            }
            $text = $next;
        }
        $guard = 0;
        while ($guard++ < 20 && preg_match($link, $text)) {
            $next = preg_replace_callback($link, function (array $match) use ($protect): string {
                return $protect(self::inline($match[1]));
            }, $text) ?? $text;
            if ($next === $text) {
                break;
            }
            $text = $next;
        }

        $codes = [];
        $text = preg_replace_callback('/`+([^`]*)`+/', function (array $match) use (&$codes): string {
            $codes[] = $match[1];

            return "\u{E000}".(count($codes) - 1)."\u{E001}";
        }, $text) ?? $text;

        $escaped = [];
        $text = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!<>])/', function (array $match) use (&$escaped): string {
            $escaped[] = $match[1];

            return "\u{E002}".(count($escaped) - 1)."\u{E003}";
        }, $text) ?? $text;

        $text = preg_replace_callback('/<((?:https?:\/\/|mailto:)[^>\s]+)>/i', fn (array $match) => $match[1], $text) ?? $text;
        $text = preg_replace_callback('/<([a-z][a-z0-9+.\-]{1,31}:[^>\s]+)>/i', fn (array $match) => $match[1], $text) ?? $text;
        $text = preg_replace_callback('/<([^>\s]+@[^>\s]+)>/', fn (array $match) => $match[1], $text) ?? $text;
        $text = preg_replace('/<\/?[a-zA-Z][^>]*>/', '', $text) ?? $text;

        $text = self::stripEmphasis($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace_callback('/\x{E002}(\d+)\x{E003}/u', function (array $match) use ($escaped): string {
            $index = (int) $match[1];

            return array_key_exists($index, $escaped) ? $escaped[$index] : $match[0];
        }, $text) ?? $text;
        $text = preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn (array $match) => $codes[(int) $match[1]] ?? '', $text) ?? $text;
        $guard = 0;
        do {
            $previous = $text;
            $text = preg_replace_callback('/\x{E004}(\d+)\x{E005}/u', function (array $match) use ($protected): string {
                $index = (int) $match[1];

                return array_key_exists($index, $protected) ? $protected[$index] : $match[0];
            }, $text) ?? $text;
        } while ($text !== $previous && $guard++ < 20);

        return $text;
    }

    private static function stripEmphasis(string $text): string
    {
        $previous = null;
        while ($previous !== $text) {
            $previous = $text;
            $text = preg_replace('/(?<!\w)\*\*\*(\S(?:.*?\S)?)\*\*\*(?!\w)/u', '$1', $text) ?? $text;
            $text = preg_replace('/(?<!\w)\*\*(\S(?:.*?\S)?)\*\*(?!\w)/u', '$1', $text) ?? $text;
            $text = preg_replace('/(?<!\w)__(\S(?:.*?\S)?)__(?!\w)/u', '$1', $text) ?? $text;
            $text = preg_replace('/(?<!\w)\*(\S(?:.*?\S)?)\*(?!\w)/u', '$1', $text) ?? $text;
            $text = preg_replace('/(?<!\S)_(\S(?:.*?\S)?)_(?!\S)/u', '$1', $text) ?? $text;
        }

        return $text;
    }
}
