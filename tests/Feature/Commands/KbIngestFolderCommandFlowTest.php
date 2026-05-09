<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\IngestDocumentJob;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Additional end-to-end coverage for the v4.2 Flow refactor of
 * `kb:ingest-folder`. Complements the existing legacy fixture
 * (preserved for back-compat regression). Asserts the new --tenant
 * surface and the Flow-driven idempotency-busting nonce.
 */
final class KbIngestFolderCommandFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_dispatches_with_tenant_id_from_option(): void
    {
        Storage::disk('kb')->put('docs/a.md', '# a');

        $this->artisan('kb:ingest-folder', [
            'path' => 'docs',
            '--project' => 'demo',
            '--tenant' => 'tenant-x',
        ])->assertSuccessful();

        Queue::assertPushed(IngestDocumentJob::class, fn ($job): bool => $job->tenantId === 'tenant-x');
    }

    public function test_re_runs_after_manual_file_addition_dispatch_again(): void
    {
        // The Flow's idempotency key is salted with hrtime — re-runs MUST
        // re-execute (otherwise newly-added files would be silently
        // skipped after the engine's per-(name, key) dedup short-circuit).
        Storage::disk('kb')->put('docs/a.md', '# a');
        $this->artisan('kb:ingest-folder', ['path' => 'docs', '--project' => 'demo'])
            ->assertSuccessful();

        Storage::disk('kb')->put('docs/b.md', '# b');
        $this->artisan('kb:ingest-folder', ['path' => 'docs', '--project' => 'demo'])
            ->assertSuccessful();

        // Total jobs across both runs: 1 + 2 = 3.
        Queue::assertPushed(IngestDocumentJob::class, 3);
    }
}
