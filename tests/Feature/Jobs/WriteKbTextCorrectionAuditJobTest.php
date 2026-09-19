<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\WriteKbTextCorrectionAuditJob;
use App\Models\KbCanonicalAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * v8.37/W3b round 7 (Copilot PR #496 must-fix) — the durable retry for
 * {@see \App\Services\Kb\Review\KbReviewService::approveCorrection()}'s
 * `kb_canonical_audit` write when the synchronous attempt fails. See the
 * job's own docblock for the full reasoning (escalated from round 4's
 * "log and give up" to a genuine queued retry, per CLAUDE.md's description
 * of `kb_canonical_audit` as an immutable forensic/compliance trail).
 */
final class WriteKbTextCorrectionAuditJobTest extends TestCase
{
    use RefreshDatabase;

    private function job(int $candidateId = 42): WriteKbTextCorrectionAuditJob
    {
        return new WriteKbTextCorrectionAuditJob(
            tenantId: 'default',
            candidateId: $candidateId,
            actor: 'user:7',
            projectKey: 'eng',
            docId: 'dec-cache-v2',
            slug: 'dec-cache-v2',
            beforeJson: ['document_id' => 1, 'version_hash' => 'aaa', 'old_text' => 'Bod'],
            afterJson: ['document_id' => 2, 'version_hash' => 'bbb', 'new_text' => 'Bob', 'page_number' => 1],
        );
    }

    public function test_handle_writes_the_audit_row(): void
    {
        $this->job()->handle();

        $this->assertDatabaseHas('kb_canonical_audit', [
            'tenant_id' => 'default',
            'event_type' => 'updated',
            'actor' => 'user:7',
        ]);
        $audit = KbCanonicalAudit::query()->where('event_type', 'updated')->latest('id')->first();
        $this->assertSame(42, $audit->metadata_json['candidate_id']);
        $this->assertSame('audit_retry_job', $audit->metadata_json['via']);
        $this->assertSame('Bod', $audit->before_json['old_text']);
        $this->assertSame('Bob', $audit->after_json['new_text']);
    }

    /**
     * The synchronous attempt in KbReviewService can fail AFTER its INSERT
     * already committed (e.g. the connection was cut acknowledging
     * success) — a retry-happy queue worker must not double the audit
     * trail for the same correction.
     */
    public function test_handle_is_a_no_op_when_a_row_already_exists_for_the_same_candidate(): void
    {
        KbCanonicalAudit::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'eng',
            'doc_id' => 'dec-cache-v2',
            'slug' => 'dec-cache-v2',
            'event_type' => 'updated',
            'actor' => 'user:7',
            'before_json' => ['document_id' => 1],
            'after_json' => ['document_id' => 2],
            'metadata_json' => ['source' => 'kb_review_correction_candidate', 'candidate_id' => 42],
        ]);

        $this->job()->handle();

        $this->assertDatabaseCount('kb_canonical_audit', 1);
    }

    public function test_different_candidates_do_not_collide(): void
    {
        $this->job(candidateId: 1)->handle();
        $this->job(candidateId: 2)->handle();

        $this->assertDatabaseCount('kb_canonical_audit', 2);
    }

    public function test_failed_logs_critical_with_every_field_needed_to_reconstruct_the_row(): void
    {
        Log::shouldReceive('critical')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'exhausted retries')
                    && $context['candidate_id'] === 42
                    && $context['tenant_id'] === 'default'
                    && $context['actor'] === 'user:7'
                    && $context['before_json']['old_text'] === 'Bod'
                    && $context['after_json']['new_text'] === 'Bob'
                    && $context['exception'] === 'connection refused';
            });

        $this->job()->failed(new \RuntimeException('connection refused'));
    }
}
