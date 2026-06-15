<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * v8.13/P11 — seed the evidence_risk_review_logs table with two reviews for the
 * `default` tenant so the Evidence & Risk Review admin happy-path Playwright
 * scenario reads REAL rows (R13): the reviews list renders both, the detail
 * drill-down loads one with its findings, and the tenant stamp is visible.
 *
 * Idempotent (updateOrInsert keyed on review_id). The rows mirror the package
 * ReviewResult shape so DatabaseReviewLogStore / ReviewLogQuery decode them
 * exactly as a live review would.
 */
final class EvidenceRiskReviewSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsertReview(
            reviewId: 'e2e-review-keep-0001',
            artifactId: 'doc-onboarding',
            maxVerdict: 'keep',
            riskScore: 0.05,
            findings: [],
            claimVerdicts: ['c1' => 'keep'],
        );

        $this->upsertReview(
            reviewId: 'e2e-review-flag-0002',
            artifactId: 'doc-medical-claim',
            maxVerdict: 'flag_for_human_review',
            riskScore: 0.72,
            findings: [[
                'check_kind' => 'claim_assertiveness',
                'claim_id' => 'c1',
                'verdict' => 'flag_for_human_review',
                'reason' => 'Definitive claim backed only by a low-tier source.',
                'suggested_rewrite' => 'Soften to "may reduce symptoms".',
                'confidence' => 0.8,
                'cost_class' => 'cheap',
                'evidence' => [],
            ]],
            claimVerdicts: ['c1' => 'flag_for_human_review'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, string>  $claimVerdicts
     */
    private function upsertReview(
        string $reviewId,
        string $artifactId,
        string $maxVerdict,
        float $riskScore,
        array $findings,
        array $claimVerdicts,
    ): void {
        $now = now();

        DB::table('evidence_risk_review_logs')->updateOrInsert(
            ['review_id' => $reviewId],
            [
                'artifact_id' => $artifactId,
                'profile_key' => 'default',
                'tenant_id' => 'default',
                'max_verdict' => $maxVerdict,
                'risk_score' => $riskScore,
                'findings' => json_encode($findings),
                'claim_verdicts' => json_encode($claimVerdicts),
                'source_tiers' => json_encode(['s1' => ['key' => 'blog', 'rank' => 2, 'label' => 'Blog', 'builtin' => true]]),
                'budget' => json_encode(['llm_calls' => 0, 'tokens' => 0, 'heavy_checks' => 0, 'wall_seconds' => 0.01]),
                'artifact' => json_encode(['artifact_id' => $artifactId, 'tenant_id' => 'default']),
                'options' => json_encode(['dry_run' => false]),
                'metadata' => json_encode(['llm_enabled' => false, 'heavy_checks_run' => false]),
                'reviewed_at' => $now,
                'created_at' => $now,
            ],
        );
    }
}
