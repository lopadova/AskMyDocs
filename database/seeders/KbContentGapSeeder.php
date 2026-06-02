<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KbSearchFailure;
use Illuminate\Database\Seeder;

/**
 * v8.8/W4 — seed a few content-gap rows (strict-monotonic occurrences + one
 * already-resolved) so the "Content Gaps" Playwright happy path can assert the
 * ranked list + resolve flow against REAL data (R13), without driving the chat
 * refusal path / AI provider. Idempotent per (tenant, project, query, reason).
 */
final class KbContentGapSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['How do I rotate the signing key in production?', 17, null],
            ['What is the on-call escalation policy?', 9, null],
            ['Where are the staging database backups stored?', 3, null],
            ['Deprecated: how to use the old export tool', 5, now()],
        ];

        foreach ($rows as [$query, $occurrences, $resolvedAt]) {
            KbSearchFailure::updateOrCreate(
                [
                    'tenant_id' => 'default',
                    'project_key' => 'eng',
                    'query_hash' => hash('sha256', mb_strtolower($query)),
                    'reason' => KbSearchFailure::REASON_NO_CONTEXT,
                ],
                [
                    'normalized_query' => mb_strtolower($query),
                    'query_text' => $query,
                    'occurrences' => $occurrences,
                    'last_seen_at' => now(),
                    'resolved_at' => $resolvedAt,
                ],
            );
        }
    }
}
