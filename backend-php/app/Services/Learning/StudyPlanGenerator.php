<?php

namespace App\Services\Learning;

class PlanCapacityException extends \InvalidArgumentException {}

final class StudyPlanGenerator
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
                'Revoir tous les thèmes de grammaire de ce niveau',
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
     * @param  list<mixed>  $units
     */
    public function assertCapacity(array $units, int $totalWeeks, int $daysPerWeek): void
    {
        if ($totalWeeks < 1 || $daysPerWeek < 1) {
            throw new PlanCapacityException(
                'Plan dimensions must be positive: both duration_weeks and days_per_week must be at least 1.'
            );
        }
        $teachingSlots = $totalWeeks * $daysPerWeek - 1;
        $unitCount = count($units);
        if ($unitCount === 0) {
            throw new PlanCapacityException(
                'This level has no curriculum units, so no study plan can be built for it.'
            );
        }
        if ($teachingSlots >= $unitCount) {
            return;
        }
        $weeksNeeded = intdiv($unitCount + 1 + $daysPerWeek - 1, $daysPerWeek);
        throw new PlanCapacityException(
            "This plan is too short to cover all {$unitCount} curriculum units: "
            ."{$totalWeeks} weeks × {$daysPerWeek} days leaves ".max($teachingSlots, 0)
            .' teaching slot(s) once the final completion test is reserved. Allow at least '
            .($unitCount + 1)." plan days in total ({$unitCount} lessons + 1 completion test). "
            ."Use at least {$weeksNeeded} weeks × {$daysPerWeek} days to give each unit "
            .'at least one lesson; more room gives better coverage.'
        );
    }

    /**
     * @param  array{cefr_level: string, duration_weeks: int, days_per_week: int}  $request
     * @return array<string, mixed>
     */
    public function generate(array $request, string $targetLanguage = 'en-GB'): array
    {
        $units = LearningCatalog::units($request['cefr_level'], $targetLanguage);
        $this->assertCapacity($units, $request['duration_weeks'], $request['days_per_week']);
        $slots = $this->distribute($units, $request['duration_weeks'], $request['days_per_week'], $targetLanguage);
        $weeks = [];
        foreach ($slots as $slot) {
            $weeks[$slot['week']][] = $slot;
        }
        ksort($weeks);
        $weekly = [];
        foreach ($weeks as $weekNum => $slotsInWeek) {
            $weekly[] = [
                'week' => $weekNum,
                'theme' => $slotsInWeek[0]['unit_title'] ?? '',
                'days' => array_map(fn (array $slot) => [
                    'day' => $slot['day'],
                    'lesson_type' => $slot['lesson_type'],
                    'title' => $slot['title'],
                    'objectives' => $slot['objectives'],
                    'estimated_minutes' => $slot['estimated_minutes'],
                    'unit_id' => $slot['unit_id'],
                    'grammar_points' => $slot['grammar_points'],
                    'vocabulary_set_ids' => $slot['vocabulary_set_ids'],
                ], $slotsInWeek),
            ];
        }

        return [
            'title' => LearningCatalog::languageName($targetLanguage).' '.$request['cefr_level'].' — '.$request['duration_weeks'].'-week programme',
            'cefr_level' => $request['cefr_level'],
            'duration_weeks' => $request['duration_weeks'],
            'days_per_week' => $request['days_per_week'],
            'ends_with_test' => true,
            'weekly_plan' => $weekly,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @return list<array<string, mixed>>
     */
    public function distribute(array $units, int $totalWeeks, int $daysPerWeek, string $targetLanguage = 'en-GB'): array
    {
        $prefix = explode('-', $targetLanguage)[0];
        $i18n = self::I18N[$targetLanguage] ?? self::I18N[$prefix] ?? self::I18N['en-GB'];
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
        $rotations = $this->selectRotations($units, $quotas);
        $slots = [];
        $level = $units[0]['level'];
        foreach ($units as $unitIndex => $unit) {
            $quota = $quotas[$unitIndex];
            $rotation = $rotations[$unitIndex];
            $types = $unit['lesson_types'] ?: ['grammar'];
            $cycle = count($types);
            $gps = $unit['grammar_points'] ?? [];
            $checklist = $unit['competency_checklist'] ?? [];
            $vocab = $unit['vocabulary_set_ids'] ?? [];
            for ($per = 0; $per < $quota; $per++) {
                $slot = count($slots);
                $title = strtr($i18n['lesson_title'], [
                    '{title}' => $unit['title'],
                    '{n}' => (string) ($per + 1),
                ]);
                $slots[] = [
                    'week' => intdiv($slot, $daysPerWeek) + 1,
                    'day' => ($slot % $daysPerWeek) + 1,
                    'unit_id' => $unit['id'],
                    'unit_title' => $unit['title'],
                    'lesson_type' => $types[($rotation + $per) % $cycle],
                    'title' => $title,
                    'objectives' => array_slice($checklist, 0, 2),
                    'estimated_minutes' => 25,
                    'grammar_points' => array_slice($gps, 0, 2),
                    'vocabulary_set_ids' => array_slice($vocab, 0, 1),
                ];
            }
        }
        $slots[] = [
            'week' => intdiv($testSlot, $daysPerWeek) + 1,
            'day' => ($testSlot % $daysPerWeek) + 1,
            'unit_id' => 'completion-test',
            'unit_title' => strtr($i18n['test_unit_title'], ['{level}' => $level]),
            'lesson_type' => 'review',
            'title' => strtr($i18n['test_title'], ['{level}' => $level]),
            'objectives' => $i18n['test_objectives'],
            'estimated_minutes' => 45,
            'grammar_points' => $units[array_key_last($units)]['grammar_points'] ?? [],
            'vocabulary_set_ids' => [],
        ];

        return $slots;
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @param  list<int>  $quotas
     * @return list<int>
     */
    public function selectRotations(array $units, array $quotas): array
    {
        $declared = [];
        $ownerCount = [];
        $order = [];
        foreach ($units as $unit) {
            $seen = [];
            foreach ($unit['lesson_types'] ?: ['grammar'] as $lessonType) {
                if (isset($seen[$lessonType])) {
                    continue;
                }
                $seen[$lessonType] = true;
                if (! isset($ownerCount[$lessonType])) {
                    $ownerCount[$lessonType] = 0;
                    $declared[] = $lessonType;
                    $order[$lessonType] = count($order);
                }
                $ownerCount[$lessonType]++;
            }
        }
        $canonical = array_flip(LearningCatalog::LESSON_TYPES);
        $priority = $declared;
        usort($priority, function (string $a, string $b) use ($ownerCount, $canonical, $order) {
            $byOwner = $ownerCount[$a] <=> $ownerCount[$b];
            if ($byOwner !== 0) {
                return $byOwner;
            }
            $byCanon = ($canonical[$a] ?? count(LearningCatalog::LESSON_TYPES)) <=> ($canonical[$b] ?? count(LearningCatalog::LESSON_TYPES));
            if ($byCanon !== 0) {
                return $byCanon;
            }

            return $order[$a] <=> $order[$b];
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
                    if (! array_key_exists($combined, $next) || $this->less($candidate, $next[$combined])) {
                        $next[$combined] = $candidate;
                    }
                }
            }
            $states = $next;
        }
        $bestMask = null;
        $bestKey = null;
        foreach (array_keys($states) as $mask) {
            $bits = [];
            $count = count($priority);
            for ($index = 0; $index < $count; $index++) {
                $bits[] = ($mask >> $index) & 1;
            }
            $key = [substr_count(decbin((int) $mask), '1'), $bits];
            if ($bestKey === null || $this->greaterKey($key, $bestKey)) {
                $bestKey = $key;
                $bestMask = (int) $mask;
            }
        }

        return $states[$bestMask] ?? [];
    }

    /**
     * @param  list<int>  $left
     * @param  list<int>  $right
     */
    private function less(array $left, array $right): bool
    {
        $n = max(count($left), count($right));
        for ($i = 0; $i < $n; $i++) {
            $a = $left[$i] ?? -1;
            $b = $right[$i] ?? -1;
            if ($a !== $b) {
                return $a < $b;
            }
        }

        return false;
    }

    /**
     * @param  array{0: int, 1: list<int>}  $left
     * @param  array{0: int, 1: list<int>}  $right
     */
    private function greaterKey(array $left, array $right): bool
    {
        if ($left[0] !== $right[0]) {
            return $left[0] > $right[0];
        }
        $n = max(count($left[1]), count($right[1]));
        for ($i = 0; $i < $n; $i++) {
            $a = $left[1][$i] ?? 0;
            $b = $right[1][$i] ?? 0;
            if ($a !== $b) {
                return $a > $b;
            }
        }

        return false;
    }
}
