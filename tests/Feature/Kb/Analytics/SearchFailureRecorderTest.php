<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Analytics;

use App\Models\KbSearchFailure;
use App\Services\Kb\Analytics\SearchFailureRecorder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.8/W4 — `SearchFailureRecorder`: upserts a per-(tenant, project,
 * normalized query, reason) content-gap rollup, increments occurrences,
 * normalizes phrasing, is tenant-scoped (R30), and is config-gated.
 */
final class SearchFailureRecorderTest extends TestCase
{
    use RefreshDatabase;

    private SearchFailureRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        $this->recorder = app(SearchFailureRecorder::class);
        config()->set('kb.content_gaps.enabled', true);
    }

    public function test_records_a_new_content_gap(): void
    {
        $this->recorder->record('eng', 'How do I rotate the signing key?', KbSearchFailure::REASON_NO_CONTEXT);

        $row = KbSearchFailure::query()->forTenant('default')->sole();
        $this->assertSame('eng', $row->project_key);
        $this->assertSame(1, $row->occurrences);
        $this->assertSame(KbSearchFailure::REASON_NO_CONTEXT, $row->reason);
        $this->assertNotNull($row->last_seen_at);
    }

    public function test_repeated_gap_increments_occurrences_idempotently(): void
    {
        // Different casing + whitespace normalize to the same bucket.
        $this->recorder->record('eng', 'Rotate the   signing key', KbSearchFailure::REASON_NO_CONTEXT);
        $this->recorder->record('eng', 'rotate the signing KEY', KbSearchFailure::REASON_NO_CONTEXT);
        $this->recorder->record('eng', 'Rotate the signing key', KbSearchFailure::REASON_NO_CONTEXT);

        $row = KbSearchFailure::query()->forTenant('default')->sole();
        $this->assertSame(3, $row->occurrences);
    }

    public function test_different_reason_is_a_separate_row(): void
    {
        $this->recorder->record('eng', 'same question', KbSearchFailure::REASON_NO_CONTEXT);
        $this->recorder->record('eng', 'same question', KbSearchFailure::REASON_SELF_REFUSAL);

        $this->assertSame(2, KbSearchFailure::query()->forTenant('default')->count());
    }

    public function test_blank_query_is_ignored(): void
    {
        $this->recorder->record('eng', '   ', KbSearchFailure::REASON_NO_CONTEXT);

        $this->assertSame(0, KbSearchFailure::query()->forTenant('default')->count());
    }

    public function test_disabled_config_records_nothing(): void
    {
        config()->set('kb.content_gaps.enabled', false);

        $this->recorder->record('eng', 'a real question', KbSearchFailure::REASON_NO_CONTEXT);

        $this->assertSame(0, KbSearchFailure::query()->forTenant('default')->count());
    }

    public function test_recording_re_opens_a_previously_resolved_gap(): void
    {
        $this->recorder->record('eng', 'recurring gap', KbSearchFailure::REASON_NO_CONTEXT);
        $row = KbSearchFailure::query()->forTenant('default')->sole();
        $row->forceFill(['resolved_at' => now()->subDay()])->save();

        // The same gap recurs — it should re-open (resolved_at cleared).
        $this->recorder->record('eng', 'recurring gap', KbSearchFailure::REASON_NO_CONTEXT);

        $this->assertNull($row->fresh()->resolved_at);
        $this->assertSame(2, $row->fresh()->occurrences);
    }

    public function test_is_tenant_scoped(): void
    {
        $this->recorder->record('eng', 'same wording', KbSearchFailure::REASON_NO_CONTEXT);
        app(TenantContext::class)->set('other');
        $this->recorder->record('eng', 'same wording', KbSearchFailure::REASON_NO_CONTEXT);

        $this->assertSame(1, KbSearchFailure::query()->forTenant('default')->count());
        $this->assertSame(1, KbSearchFailure::query()->forTenant('other')->count());
    }
}
