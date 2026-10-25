<?php

namespace App\Services\Catalog;

final class CurriculumPlanner
{
    /** @var list<string> */
    private const LESSON_TYPES = ['grammar', 'vocabulary', 'reading', 'writing', 'listening', 'speaking', 'review'];

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
                'Réussir l\'évaluation pour débloquer le niveau suivant',
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
     * @return list<array<string, mixed>>
     */
    public static function distribute(array $units, int $totalWeeks, int $daysPerWeek, string $targetLanguage = 'en-GB'): array
    {
        $i18n = self::i18n($targetLanguage);
        $totalSlots = $totalWeeks * $daysPerWeek;
        $nUnits = count($units);
        if ($nUnits === 0) {
            return [];
        }
        $teachingSlots = $totalSlots - 1;
        $testSlot = $totalSlots - 1;
        $baseQuota = intdiv($teachingSlots, $nUnits);
        $remainder = $teachingSlots % $nUnits;
        $quotas = [];
        for ($i = 0; $i < $nUnits; $i++) {
            $quotas[] = $baseQuota + ($i < $remainder ? 1 : 0);
        }
        $rotations = self::selectRotations($units, $quotas);
        $slots = [];
        $level = $units[0]['level'] ?? '';
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
                $slots[] = [
                    'week' => intdiv($slot, $daysPerWeek) + 1,
                    'day' => ($slot % $daysPerWeek) + 1,
                    'unit_id' => $unit['id'],
                    'unit_title' => $unit['title'],
                    'lesson_type' => $types[($rotation + $per) % $cycle],
                    'title' => strtr($i18n['lesson_title'], ['{title}' => $unit['title'], '{n}' => (string) ($per + 1)]),
                    'objectives' => array_slice($checklist, 0, 2),
                    'estimated_minutes' => 25,
                    'grammar_points' => array_slice($gps, 0, 2),
                    'vocabulary_set_ids' => array_slice($vocab, 0, 1),
                ];
            }
        }
        $last = $units[$nUnits - 1];
        $slots[] = [
            'week' => intdiv($testSlot, $daysPerWeek) + 1,
            'day' => ($testSlot % $daysPerWeek) + 1,
            'unit_id' => Catalog::COMPLETION_UNIT_ID,
            'unit_title' => strtr($i18n['test_unit_title'], ['{level}' => $level]),
            'lesson_type' => 'review',
            'title' => strtr($i18n['test_title'], ['{level}' => $level]),
            'objectives' => $i18n['test_objectives'],
            'estimated_minutes' => 45,
            'grammar_points' => $last['grammar_points'] ?? [],
            'vocabulary_set_ids' => [],
        ];

        return $slots;
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @param  list<int>  $quotas
     * @return list<int>
     */
    private static function selectRotations(array $units, array $quotas): array
    {
        $declared = [];
        $ownerCount = [];
        foreach ($units as $unit) {
            $seen = [];
            foreach (($unit['lesson_types'] ?: ['grammar']) as $type) {
                if (isset($seen[$type])) {
                    continue;
                }
                $seen[$type] = true;
                if (! isset($ownerCount[$type])) {
                    $ownerCount[$type] = 0;
                    $declared[] = $type;
                }
                $ownerCount[$type]++;
            }
        }
        $canonical = array_flip(self::LESSON_TYPES);
        usort($declared, function (string $a, string $b) use ($ownerCount, $canonical) {
            $cmp = $ownerCount[$a] <=> $ownerCount[$b];
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($canonical[$a] ?? 99) <=> ($canonical[$b] ?? 99);
        });
        $typeIndex = array_flip($declared);
        $states = [0 => []];
        foreach ($units as $i => $unit) {
            $cycle = $unit['lesson_types'] ?: ['grammar'];
            $length = count($cycle);
            $quota = $quotas[$i];
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
                    $candidate = array_merge($chosen, [$rotation]);
                    if (! isset($next[$combined]) || self::tupleLess($candidate, $next[$combined])) {
                        $next[$combined] = $candidate;
                    }
                }
            }
            $states = $next;
        }
        $best = null;
        $bestScore = null;
        foreach ($states as $mask => $chosen) {
            $bits = [];
            for ($index = 0; $index < count($declared); $index++) {
                $bits[] = ($mask >> $index) & 1;
            }
            $score = [self::bitCount($mask), $bits];
            if ($best === null || self::scoreGreater($score, $bestScore)) {
                $best = $mask;
                $bestScore = $score;
            }
        }

        return $states[$best];
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function tupleLess(array $a, array $b): bool
    {
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] < $b[$i]) {
                return true;
            }
            if ($a[$i] > $b[$i]) {
                return false;
            }
        }

        return count($a) < count($b);
    }

    /**
     * @param  array{0: int, 1: list<int>}  $a
     * @param  array{0: int, 1: list<int>}  $b
     */
    private static function scoreGreater(array $a, array $b): bool
    {
        if ($a[0] !== $b[0]) {
            return $a[0] > $b[0];
        }

        return $a[1] > $b[1];
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

    /**
     * @return array<string, mixed>
     */
    private static function i18n(string $targetLanguage): array
    {
        if (isset(self::I18N[$targetLanguage])) {
            return self::I18N[$targetLanguage];
        }
        $prefix = explode('-', $targetLanguage)[0];

        return self::I18N[$prefix] ?? self::I18N['en-GB'];
    }
}
