<?php

declare(strict_types=1);

namespace App\Services\Kb;

use App\Models\KnowledgeDocument;

final class KbHealthService
{
    /**
     * @return array{health_score:int,factors:array<string,float|int>}
     */
    public function score(KnowledgeDocument $doc): array
    {
        $weights = (array) config('askmydocs.kb_health.weights', []);
        $weightAge = (float) ($weights['age_decay'] ?? 0.25);
        $weightRepeat = (float) ($weights['repeat_questions'] ?? 0.20);
        $weightSupersedes = (float) ($weights['supersedes_chain'] ?? 0.20);
        $weightOrphan = (float) ($weights['orphan_outbound'] ?? 0.15);
        $weightStatus = (float) ($weights['status_decay'] ?? 0.20);

        $indexedAt = $doc->indexed_at;
        $ageDays = $indexedAt ? (int) $indexedAt->diffInDays(now()) : 365;
        $ageFactor = min(100.0, $ageDays / 3.65); // 0..100 over ~1y

        $meta = (array) ($doc->metadata ?? []);
        $repeatQuestions = (int) ($meta['repeat_questions_30d'] ?? 0);
        $repeatFactor = min(100.0, $repeatQuestions * 10.0);

        $supersedesDepth = (int) data_get($doc->frontmatter_json ?? [], 'supersedes_depth', 0);
        $supersedesFactor = min(100.0, $supersedesDepth * 25.0);

        $orphanOutbound = (bool) data_get($doc->frontmatter_json ?? [], 'orphan_outbound', false);
        $orphanFactor = $orphanOutbound ? 100.0 : 0.0;

        $status = (string) ($doc->canonical_status ?? '');
        $statusFactor = match ($status) {
            'accepted' => 0.0,
            'draft' => 30.0,
            'review' => 45.0,
            'superseded' => 90.0,
            'deprecated' => 80.0,
            'archived' => 70.0,
            default => 50.0,
        };

        $raw = ($ageFactor * $weightAge)
            + ($repeatFactor * $weightRepeat)
            + ($supersedesFactor * $weightSupersedes)
            + ($orphanFactor * $weightOrphan)
            + ($statusFactor * $weightStatus);

        $score = (int) max(0, min(100, round($raw)));

        return [
            'health_score' => $score,
            'factors' => [
                'age_days' => $ageDays,
                'age_factor' => $ageFactor,
                'repeat_questions_30d' => $repeatQuestions,
                'repeat_factor' => $repeatFactor,
                'supersedes_depth' => $supersedesDepth,
                'supersedes_factor' => $supersedesFactor,
                'orphan_outbound_factor' => $orphanFactor,
                'status_factor' => $statusFactor,
            ],
        ];
    }
}

