<?php

namespace App\Services\Practice;

final class PlanCapacityError extends \RuntimeException
{
}

final class CurriculumPlanner
{
    /** @var array<string, array<string, mixed>> */
    private const I18N = [
        'en-GB' => [
            'lesson_title' => '{title} - Lesson {n}',
            'test_unit_title' => 'Level {level} Completion Test',
            'test_title' => 'Level {level} Completion Test',
            'test_objectives' => [
                'Review all grammar topics from this level',
                'Complete the assessment to unlock the next level',
            ],
        ],
        'en-US' => [
            'lesson_title' => '{title} - Lesson {n}',
            'test_unit_title' => 'Level {level} Completion Test',
            'test_title' => 'Level {level} Completion Test',
            'test_objectives' => [
                'Review all grammar topics from this level',
                'Complete the assessment to unlock the next level',
            ],
        ],
        'es-ES' => [
            'lesson_title' => '{title} - Lección {n}',
            'test_unit_title' => 'Examen de nivel {level}',
            'test_title' => 'Examen de nivel {level}',
            'test_objectives' => [
                'Repasar todos los temas de gramática de este nivel',
                'Completar la evaluación para desbloquear el siguiente nivel',
            ],
        ],
        'it-IT' => [
            'lesson_title' => '{title} - Lezione {n}',
            'test_unit_title' => 'Test di livello {level}',
            'test_title' => 'Test di livello {level}',
            'test_objectives' => [
                'Ripassare tutti gli argomenti di grammatica di questo livello',
                'Completare la valutazione per sbloccare il livello successivo',
            ],
        ],
        'pt-PT' => [
            'lesson_title' => '{title} - Lição {n}',
            'test_unit_title' => 'Exame de nível {level}',
            'test_title' => 'Exame de nível {level}',
            'test_objectives' => [
                'Rever todos os tópicos de gramática deste nível',
                'Concluir a avaliação para desbloquear o próximo nível',
            ],
        ],
        'fr-FR' => [
            'lesson_title' => '{title} - Leçon {n}',
            'test_unit_title' => 'Test de niveau {level}',
            'test_title' => 'Test de niveau {level}',
            'test_objectives' => [
                "Revoir tous les thèmes de grammaire de ce niveau",
                "Réussir l'évaluation pour débloquer le niveau suivant",
            ],
        ],
        'de-DE' => [
            'lesson_title' => '{title} - Lektion {n}',
            'test_unit_title' => 'Niveautest {level}',
            'test_title' => 'Niveautest {level}',
            'test_objectives' => [
                'Alle Grammatikthemen dieses Niveaus wiederholen',
                'Die Prüfung bestehen, um das nächste Niveau freizuschalten',
            ],
        ],
        'ja-JP' => [
            'lesson_title' => '{title} - レッスン {n}',
            'test_unit_title' => '{level} レベル修了テスト',
            'test_title' => '{level} レベル修了テスト',
            'test_objectives' => [
                'このレベルの文法項目を復習する',
                '評価を完了して次のレベルを解放する',
            ],
        ],
        'ko-KR' => [
            'lesson_title' => '{title} - 레슨 {n}',
            'test_unit_title' => '{level} 레벨 완료 테스트',
            'test_title' => '{level} 레벨 완료 테스트',
            'test_objectives' => [
                '이 레벨의 모든 문법 항목을 복습하기',
                '평가를 완료하여 다음 레벨 잠금 해제하기',
            ],
        ],
        'zh-CN' => [
            'lesson_title' => '{title} - 第 {n} 课',
            'test_unit_title' => '{level} 等级完成测试',
            'test_title' => '{level} 等级完成测试',
            'test_objectives' => [
                '复习本等级的所有语法项目',
                '完成评估以解锁下一个等级',
            ],
        ],
    ];

    /**
     * @param  list<array<string, mixed>>  $units
     */
    public static function assertPlanCapacity(array $units, int $totalWeeks, int $daysPerWeek): void
    {
        if ($totalWeeks < 1 || $daysPerWeek < 1) {
            throw new PlanCapacityError(
                'Plan dimensions must be positive: both duration_weeks and days_per_week must be at least 1.'
            );
        }
        $teachingSlots = $totalWeeks * $daysPerWeek - 1;
        $unitCount = count($units);
        if ($unitCount === 0) {
            throw new PlanCapacityError(
                'This level has no curriculum units, so no study plan can be built for it.'
            );
        }
        if ($teachingSlots >= $unitCount) {
            return;
        }
        $weeksNeeded = intdiv($unitCount + 1 + $daysPerWeek - 1, $daysPerWeek);
        throw new PlanCapacityError(
            "This plan is too short to cover all {$unitCount} curriculum units: "
            ."{$totalWeeks} weeks × {$daysPerWeek} days leaves ".max($teachingSlots, 0)
            .' teaching slot(s) once the final completion test is reserved. Allow at least '
            .($unitCount + 1)." plan days in total ({$unitCount} lessons + 1 completion test). "
            ."Use at least {$weeksNeeded} weeks × {$daysPerWeek} days to give each unit "
            .'at least one lesson; more room gives better coverage.'
        );
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @param  list<int>  $quotas
     * @return list<int>
     */
    public static function selectRotations(array $units, array $quotas): array
    {
        $declared = [];
        $ownerCount = [];
        foreach ($units as $unit) {
            $types = $unit['lesson_types'] ?: ['grammar'];
            $seen = [];
            foreach ($types as $lessonType) {
                if (isset($seen[$lessonType])) {
                    continue;
                }
                $seen[$lessonType] = true;
                if (! isset($ownerCount[$lessonType])) {
                    $ownerCount[$lessonType] = 0;
                    $declared[] = $lessonType;
                }
                $ownerCount[$lessonType]++;
            }
        }
        $canonical = array_flip(Catalog::LESSON_TYPES);
        $priority = $declared;
        usort($priority, function (string $a, string $b) use ($ownerCount, $canonical): int {
            $count = $ownerCount[$a] <=> $ownerCount[$b];
            if ($count !== 0) {
                return $count;
            }

            return ($canonical[$a] ?? count(Catalog::LESSON_TYPES)) <=> ($canonical[$b] ?? count(Catalog::LESSON_TYPES));
        });
        $typeIndex = array_flip($priority);
        $states = [0 => []];
        foreach ($units as $index => $unit) {
            $quota = $quotas[$index];
            $cycle = $unit['lesson_types'] ?: ['grammar'];
            $length = count($cycle);
            $rotations = ($quota > 0 && $quota < $length) ? range(0, $length - 1) : [0];
            $candidates = [];
            foreach ($rotations as $rotation) {
                $mask = 0;
                $steps = min($quota, $length);
                for ($step = 0; $step < $steps; $step++) {
                    $mask |= 1 << $typeIndex[$cycle[($rotation + $step) % $length]];
                }
                $candidates[] = [$rotation, $mask];
            }
            $next = [];
            foreach ($states as $mask => $chosen) {
                foreach ($candidates as [$rotation, $added]) {
                    $combined = $mask | $added;
                    $candidate = [...$chosen, $rotation];
                    if (! isset($next[$combined]) || self::tupleLess($candidate, $next[$combined])) {
                        $next[$combined] = $candidate;
                    }
                }
            }
            $states = $next;
        }
        $best = null;
        $bestKey = null;
        foreach ($states as $mask => $chosen) {
            $bits = self::bitCount($mask);
            $suffix = [];
            for ($i = 0; $i < count($priority); $i++) {
                $suffix[] = ($mask >> $i) & 1;
            }
            $key = [$bits, $suffix];
            if ($bestKey === null || self::coverageGreater($key, $bestKey)) {
                $bestKey = $key;
                $best = $chosen;
            }
        }

        return $best ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @return list<array<string, mixed>>
     */
    public static function distributeUnits(array $units, int $totalWeeks, int $daysPerWeek, string $targetLanguage = 'en-GB'): array
    {
        $i18n = self::I18N[$targetLanguage] ?? self::I18N[explode('-', $targetLanguage)[0]] ?? self::I18N['en-GB'];
        $totalSlots = $totalWeeks * $daysPerWeek;
        $teachingSlots = $totalSlots - 1;
        $testSlot = $totalSlots - 1;
        $nUnits = count($units);
        if ($nUnits === 0) {
            return [];
        }
        $base = intdiv($teachingSlots, $nUnits);
        $remainder = $teachingSlots % $nUnits;
        $quotas = [];
        for ($i = 0; $i < $nUnits; $i++) {
            $quotas[] = $base + ($i < $remainder ? 1 : 0);
        }
        $rotations = self::selectRotations($units, $quotas);
        $slots = [];
        $level = $units[0]['level'];
        foreach ($units as $unitIndex => $unit) {
            $quota = $quotas[$unitIndex];
            $rotation = $rotations[$unitIndex];
            $ltList = $unit['lesson_types'] ?: ['grammar'];
            $cycle = count($ltList);
            $gps = $unit['grammar_points'] ?: [];
            $checklist = $unit['competency_checklist'] ?: [];
            $vocabIds = $unit['vocabulary_set_ids'] ?: [];
            for ($perUnit = 0; $perUnit < $quota; $perUnit++) {
                $lt = $ltList[($rotation + $perUnit) % $cycle];
                $slot = count($slots);
                $slots[] = [
                    'week' => intdiv($slot, $daysPerWeek) + 1,
                    'day' => ($slot % $daysPerWeek) + 1,
                    'unit_id' => $unit['id'],
                    'unit_title' => $unit['title'],
                    'lesson_type' => $lt,
                    'title' => Text::pyFormat($i18n['lesson_title'], ['title' => $unit['title'], 'n' => $perUnit + 1]),
                    'objectives' => array_slice($checklist, 0, 2),
                    'estimated_minutes' => 25,
                    'grammar_points' => array_slice($gps, 0, 2),
                    'vocabulary_set_ids' => array_slice($vocabIds, 0, 1),
                ];
            }
        }
        $slots[] = [
            'week' => intdiv($testSlot, $daysPerWeek) + 1,
            'day' => ($testSlot % $daysPerWeek) + 1,
            'unit_id' => Catalog::COMPLETION_UNIT_ID,
            'unit_title' => Text::pyFormat($i18n['test_unit_title'], ['level' => $level]),
            'lesson_type' => 'review',
            'title' => Text::pyFormat($i18n['test_title'], ['level' => $level]),
            'objectives' => $i18n['test_objectives'],
            'estimated_minutes' => 45,
            'grammar_points' => $units[array_key_last($units)]['grammar_points'],
            'vocabulary_set_ids' => [],
        ];

        return $slots;
    }

    /**
     * @param  list<int>  $left
     * @param  list<int>  $right
     */
    private static function tupleLess(array $left, array $right): bool
    {
        $count = min(count($left), count($right));
        for ($i = 0; $i < $count; $i++) {
            if ($left[$i] < $right[$i]) {
                return true;
            }
            if ($left[$i] > $right[$i]) {
                return false;
            }
        }

        return count($left) < count($right);
    }

    /**
     * @param  array{0: int, 1: list<int>}  $left
     * @param  array{0: int, 1: list<int>}  $right
     */
    private static function coverageGreater(array $left, array $right): bool
    {
        if ($left[0] !== $right[0]) {
            return $left[0] > $right[0];
        }
        $count = min(count($left[1]), count($right[1]));
        for ($i = 0; $i < $count; $i++) {
            if ($left[1][$i] !== $right[1][$i]) {
                return $left[1][$i] > $right[1][$i];
            }
        }

        return false;
    }

    private static function bitCount(int $mask): int
    {
        $count = 0;
        while ($mask) {
            $count += $mask & 1;
            $mask >>= 1;
        }

        return $count;
    }
}
