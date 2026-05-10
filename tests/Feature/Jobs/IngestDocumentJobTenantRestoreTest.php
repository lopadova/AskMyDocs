<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Ai\EmbeddingsResponse;
use App\Jobs\IngestDocumentJob;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * R30 — proves {@see IngestDocumentJob::handle()} restores the previous
 * tenant on the {@see TenantContext} singleton on exit (success AND
 * failure paths). Long-lived queue workers drain many jobs per PHP boot
 * sharing the same container singletons; without restore-on-exit, this
 * job's tenant bleeds into the next job's worker context.
 *
 * Closes Copilot PR #115 review iteration 2 finding #3.
 */
final class IngestDocumentJobTenantRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(static fn () => [0.1, 0.2, 0.3], $texts),
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_handle_restores_previous_tenant_after_successful_run(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nBody.");

        $tenantContext = $this->app->make(TenantContext::class);

        // Worker process is on tenant-x (e.g. mid-way through a previous
        // job that itself set tenant-x and didn't restore — without our
        // try/finally that bleed would persist to this job's NEXT sibling).
        $tenantContext->set('tenant-x');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'docs/intro.md',
            disk: 'kb',
            title: 'Hello',
            tenantId: 'tenant-y',
        );

        $this->app->call([$job, 'handle']);

        $this->assertSame(
            'tenant-x',
            $tenantContext->current(),
            'IngestDocumentJob::handle must restore the previous tenant_id on the singleton on exit.',
        );
    }

    public function test_handle_restores_previous_tenant_even_when_flow_throws(): void
    {
        // Force a failure by pointing at a missing file — the parse-markdown
        // step blows up, the saga aborts, handle() re-throws RuntimeException,
        // and the `finally` MUST still restore the tenant.
        Storage::fake('kb');

        $tenantContext = $this->app->make(TenantContext::class);
        $tenantContext->set('tenant-x');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'missing-file.md',
            disk: 'kb',
            tenantId: 'tenant-y',
        );

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Expected RuntimeException from missing-file ingest.');
        } catch (\RuntimeException $e) {
            // Expected — saga failed because the source file is missing.
        }

        $this->assertSame(
            'tenant-x',
            $tenantContext->current(),
            'IngestDocumentJob::handle must restore the previous tenant_id on FAILURE too (try/finally).',
        );
    }
}
