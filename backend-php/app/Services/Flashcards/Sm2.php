<?php

namespace App\Services\Flashcards;

use Illuminate\Support\Carbon;

final class Sm2
{
    /**
     * @param  array{repetitions?: int, interval?: int, ease_factor?: float|int, next_review?: string|null}  $card
     * @return array{repetitions: int, interval: int, ease_factor: float, next_review: string}
     */
    public static function update(array $card, int $quality, ?string $today = null): array
    {
        $repetitions = (int) ($card['repetitions'] ?? 0);
        $interval = (int) ($card['interval'] ?? 0);
        $ease = (float) ($card['ease_factor'] ?? 2.5);

        if ($quality < 3) {
            $repetitions = 0;
            $interval = 1;
        } else {
            if ($repetitions === 0) {
                $interval = 1;
            } elseif ($repetitions === 1) {
                $interval = 6;
            } else {
                $interval = (int) round($interval * $ease, 0, PHP_ROUND_HALF_EVEN);
            }
            $repetitions += 1;
        }

        $ease = max(1.3, $ease + 0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02));
        $base = $today ? Carbon::parse($today) : Carbon::today();

        return [
            'repetitions' => $repetitions,
            'interval' => $interval,
            'ease_factor' => $ease,
            'next_review' => $base->copy()->addDays($interval)->toDateString(),
        ];
    }

    public static function cleanGeneratedWord(string $value): string
    {
        $cleaned = trim($value, " \t\n\r\0\x0B\"'");
        $previous = null;
        while ($previous !== $cleaned) {
            $previous = $cleaned;
            $cleaned = preg_replace('/\s*\([^()]*\)/', '', $cleaned) ?? $cleaned;
        }
        $cleaned = preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }
}
