<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use RuntimeException;

final class GoldCategoryClassifier
{
    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $scenarios
     * @param  array<string, int>  $remainingCapacity
     */
    public function classify(array $record, array $scenarios, array $remainingCapacity): string
    {
        $haystack = strtolower(
            (string) ($record['subject'] ?? '')."\n".(string) ($record['body_text'] ?? '')
        );
        $candidates = [];

        foreach ($scenarios as $scenarioKey => $scenario) {
            $capacity = $remainingCapacity[$scenarioKey] ?? 0;
            if ($capacity < 1) {
                continue;
            }

            $score = 0;
            foreach ((array) ($scenario['keywords'] ?? []) as $keyword) {
                if (str_contains($haystack, strtolower((string) $keyword))) {
                    $score++;
                }
            }

            $candidates[] = [
                'key' => $scenarioKey,
                'score' => $score,
                'capacity' => $capacity,
            ];
        }

        if ($candidates === []) {
            throw new RuntimeException('No category capacity remains while classifying the gold dataset.');
        }

        usort($candidates, static function (array $left, array $right): int {
            $byScore = $right['score'] <=> $left['score'];
            if ($byScore !== 0) {
                return $byScore;
            }

            $byCapacity = $right['capacity'] <=> $left['capacity'];

            return $byCapacity !== 0 ? $byCapacity : strcmp($left['key'], $right['key']);
        });

        return (string) $candidates[0]['key'];
    }
}
