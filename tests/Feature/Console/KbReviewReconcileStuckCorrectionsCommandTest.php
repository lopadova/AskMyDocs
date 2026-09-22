<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KbTextCorrectionCandidate;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v8.37/W3b round 5 (Copilot PR #496, H-B) — `kb:review-reconcile-stuck-corrections`,
 * the thin CLI dispatcher over {@see KbReviewService::reconcileStuckCorrections()}.
 * Service-level behaviour (finalize vs. revert vs. skip) is covered in
 * `KbReviewCorrectionTest`; this file only exercises the command's own
 * responsibilities: tenant discovery/iteration, option parsing, exit code.
 */
final class KbReviewReconcileStuckCorrectionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        config(['kb.review.enabled' => true]);
    }

    private function stuckCandidate(string $tenantId, int $minutesOld = 20): KbTextCorrectionCandidate
    {
        app(TenantContext::class)->set($tenantId);
        $doc = KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => 'eng',
            'source_type' => 'image',
            'source_path' => 'scans/c-'.bin2hex(random_bytes(4)).'.pdf',
            'title' => 'Scanned contract',
            'mime_type' => 'application/pdf',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => bin2hex(random_bytes(16)),
            'is_canonical' => false,
            'generation_source' => GenerationSource::Auto->value,
            'metadata' => ['converter' => ['page_count' => 1]],
        ]);
        \App\Models\KnowledgeChunk::create([
            'tenant_id' => $tenantId,
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'body'),
            'chunk_text' => "## Page 1\n\nBod is the supplier.\n",
            'metadata' => [],
        ]);
        $candidate = app(KbReviewService::class)->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        DB::table('kb_text_correction_candidates')->where('id', $candidate->id)->update([
            'status' => KbTextCorrectionCandidate::STATUS_APPLYING,
            'consumed_at' => now()->subMinutes($minutesOld),
        ]);

        return $candidate->fresh();
    }

    public function test_reverts_a_stuck_candidate_in_the_default_tenant(): void
    {
        $candidate = $this->stuckCandidate('default');

        $this->artisan('kb:review-reconcile-stuck-corrections')->assertExitCode(0);

        $this->assertSame(KbTextCorrectionCandidate::STATUS_PENDING, $candidate->fresh()->status);
    }

    public function test_restricts_to_the_explicit_tenant_option(): void
    {
        $inTenant = $this->stuckCandidate('tenant-a');
        $otherTenant = $this->stuckCandidate('tenant-b');

        $this->artisan('kb:review-reconcile-stuck-corrections', ['--tenant' => 'tenant-a'])->assertExitCode(0);

        $this->assertSame(KbTextCorrectionCandidate::STATUS_PENDING, $inTenant->fresh()->status);
        $this->assertSame(KbTextCorrectionCandidate::STATUS_APPLYING, $otherTenant->fresh()->status, 'a candidate outside --tenant must never be touched');
    }

    public function test_older_than_option_overrides_the_configured_threshold(): void
    {
        config(['kb.review.stuck_applying_minutes' => 15]);
        // Only 5 minutes stuck — below the configured 15-minute default,
        // but --older-than=1 makes it eligible for THIS run.
        $candidate = $this->stuckCandidate('default', minutesOld: 5);

        $this->artisan('kb:review-reconcile-stuck-corrections', ['--older-than' => 1])->assertExitCode(0);

        $this->assertSame(KbTextCorrectionCandidate::STATUS_PENDING, $candidate->fresh()->status);
    }

    public function test_no_stuck_candidates_is_a_clean_no_op(): void
    {
        $this->artisan('kb:review-reconcile-stuck-corrections')->assertExitCode(0);
    }
}
