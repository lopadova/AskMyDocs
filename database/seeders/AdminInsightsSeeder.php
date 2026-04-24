<?php

namespace Database\Seeders;

use App\Models\AdminInsightsSnapshot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phase I — seed one insights snapshot row for today so the
 * /app/admin/insights Playwright happy path paints deterministic
 * widgets WITHOUT depending on the 05:00 scheduler having fired.
 *
 * Every payload column is populated with a small deterministic
 * shape so the SPA's 6 cards exercise real `data-state="ready"`
 * transitions. Idempotent — updateOrCreate keyed on snapshot_date.
 */
class AdminInsightsSeeder extends Seeder
{
    public function run(): void
    {
        $date = Carbon::today()->toDateString();

        $row = AdminInsightsSnapshot::query()
            ->whereDate('snapshot_date', $date)
            ->first();

        $payload = [
            'suggest_promotions' => [
                [
                    'document_id' => 101,
                    'project_key' => 'hr-portal',
                    'slug' => 'candidate-doc',
                    'title' => 'Candidate — Deploy Runbook',
                    'reason' => 'Cited 8 times in the last 30 days without canonical frontmatter.',
                    'score' => 8,
                ],
            ],
            'orphan_docs' => [
                [
                    'document_id' => 202,
                    'project_key' => 'engineering',
                    'slug' => 'orphan-doc',
                    'title' => 'Orphan — Legacy Runbook',
                    'last_used_at' => null,
                    'chunks_count' => 3,
                ],
            ],
            'suggested_tags' => [
                [
                    'document_id' => 303,
                    'project_key' => 'hr-portal',
                    'slug' => 'pto-guidelines',
                    'title' => 'PTO Guidelines',
                    'tags_proposed' => ['leave', 'accrual', 'manager-approval'],
                ],
            ],
            'coverage_gaps' => [
                [
                    'topic' => 'SSO / identity',
                    'zero_citation_count' => 4,
                    'low_confidence_count' => 2,
                    'sample_questions' => [
                        'Where is the SSO url?',
                        'How do I reset MFA?',
                    ],
                ],
            ],
            'stale_docs' => [
                [
                    'document_id' => 404,
                    'project_key' => 'engineering',
                    'slug' => 'stale-runbook',
                    'title' => 'Stale — Old deploy runbook',
                    'indexed_at' => Carbon::now()->subYear()->toIso8601String(),
                    'negative_rating_ratio' => 0.75,
                ],
            ],
            'quality_report' => [
                'chunk_length_distribution' => [
                    'under_100' => 2,
                    'h100_500' => 11,
                    'h500_1000' => 5,
                    'h1000_2000' => 1,
                    'over_2000' => 0,
                ],
                'outlier_short' => 2,
                'outlier_long' => 0,
                'missing_frontmatter' => 1,
                'total_docs' => 3,
                'total_chunks' => 19,
            ],
            'computed_at' => Carbon::now(),
            'computed_duration_ms' => 1234,
        ];

        if ($row !== null) {
            $row->update($payload);
        } else {
            AdminInsightsSnapshot::create(array_merge(
                ['snapshot_date' => $date],
                $payload,
            ));
        }
    }
}
