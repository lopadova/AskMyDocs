<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Ai\EmbeddingsResponse;
use App\Flow\Definitions\IngestDocumentFlow;
use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

/**
 * End-to-end feature coverage for the {@see IngestDocumentFlow} saga.
 *
 * Asserts the engine wires the 5 steps together correctly, populates
 * tenant_id on every persisted Flow row, dedupes by (tenant_id,
 * idempotency_key), keeps tenant boundaries intact, and unwinds the
 * persist step via {@see \App\Flow\Compensators\RollbackChunksCompensator}
 * when a downstream step fails.
 */
final class IngestDocumentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        // Stub the embeddings provider once per test so the saga's
        // embed-chunks step doesn't reach a real API.
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
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
        Mockery::close();
    }

    public function test_happy_path_persists_document_and_writes_flow_persistence_rows(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nFirst paragraph.\n\n## Sub\n\nSecond paragraph.");

        $run = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('default', 'demo', 'docs/intro.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'default:demo:docs/intro.md',
                correlationId: 'default',
            ),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, KnowledgeDocument::count());

        // Persisted Flow rows live alongside the run id.
        $runRow = DB::table('flow_runs')->where('id', $run->id)->first();
        $this->assertNotNull($runRow);
        $this->assertSame('default', $runRow->tenant_id);
        $this->assertSame('succeeded', $runRow->status);
        $this->assertSame(IngestDocumentFlow::NAME, $runRow->definition_name);

        $stepRows = DB::table('flow_steps')
            ->where('run_id', $run->id)
            ->orderBy('sequence')
            ->get();
        $stepNames = $stepRows->pluck('step_name')->all();
        $this->assertSame([
            'parse-markdown',
            'chunk-document',
            'embed-chunks',
            'persist-chunks',
            'maybe-dispatch-canonical-indexer',
            'maybe-dispatch-collections-evaluator',
        ], $stepNames);
        foreach ($stepRows as $stepRow) {
            $this->assertSame('default', $stepRow->tenant_id);
        }

        $auditCount = DB::table('flow_audit')->where('run_id', $run->id)->count();
        $this->assertGreaterThan(0, $auditCount);
    }

    public function test_idempotency_returns_existing_run_on_redispatch(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nFirst paragraph.");

        $first = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('default', 'demo', 'docs/intro.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'default:demo:docs/intro.md',
                correlationId: 'default',
            ),
        );

        $second = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('default', 'demo', 'docs/intro.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'default:demo:docs/intro.md',
                correlationId: 'default',
            ),
        );

        $this->assertSame($first->id, $second->id, 'Re-dispatch with same idempotency key must return existing FlowRun.');
        $this->assertSame(1, DB::table('flow_runs')->count());
        $this->assertSame(1, KnowledgeDocument::count(), 'No duplicate KnowledgeDocument should be inserted.');
    }

    public function test_tenant_isolation_two_tenants_yield_distinct_flow_runs_and_documents(): void
    {
        Storage::fake('kb');
        // Use distinct content per tenant so the inner content-hash dedup
        // (DocumentIngestor::findExistingVersion) treats them as separate
        // documents. The R30 read-scoping concern in DocumentIngestor's
        // version-hash lookup is pre-existing and orthogonal to this PR.
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nTenant content varies per scenario.");

        // Production path: callers set the active tenant on TenantContext
        // BEFORE calling Flow::execute(), so the engine's FlowRunRecord
        // insert is stamped with the right tenant. IngestDocumentJob does
        // this in handle() — mirror it here.
        $tenantContext = $this->app->make(TenantContext::class);

        $tenantContext->set('tenant-a');
        $runA = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('tenant-a', 'demo', 'docs/intro.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'tenant-a:demo:docs/intro.md',
                correlationId: 'tenant-a',
            ),
        );

        // Distinct content for tenant-b so it doesn't collide on
        // version_hash with tenant-a's copy in the (currently
        // tenant-naive) findExistingVersion lookup.
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nDifferent content for tenant-b.");
        $tenantContext->set('tenant-b');
        $runB = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('tenant-b', 'demo', 'docs/intro.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'tenant-b:demo:docs/intro.md',
                correlationId: 'tenant-b',
            ),
        );

        $this->assertNotSame($runA->id, $runB->id);
        $this->assertSame(2, DB::table('flow_runs')->count());

        $tenants = DB::table('flow_runs')->pluck('tenant_id')->sort()->values()->all();
        $this->assertSame(['tenant-a', 'tenant-b'], $tenants);

        // R30 — knowledge_documents inserted under their respective tenants.
        $tenantADocs = KnowledgeDocument::where('tenant_id', 'tenant-a')->count();
        $tenantBDocs = KnowledgeDocument::where('tenant_id', 'tenant-b')->count();
        $this->assertSame(1, $tenantADocs);
        $this->assertSame(1, $tenantBDocs);
    }

    public function test_compensation_force_deletes_document_when_canonical_indexer_step_fails(): void
    {
        Storage::fake('kb');
        $canonical = <<<'MD'
---
type: decision
status: accepted
slug: dec-comp-rollback
id: dec-cmp-001
---
# Compensation test
Body.
MD;
        Storage::disk('kb')->put('docs/dec.md', $canonical);

        // Force the maybe-dispatch-canonical-indexer step to fail by
        // stubbing the queue dispatcher to throw on dispatch.
        $bus = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('forced indexer dispatch failure'));
        // Allow the engine's RunFlowJob path (if any) but we run sync
        // here; the only dispatch we're shadowing is CanonicalIndexerJob.
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);

        $run = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('default', 'demo', 'docs/dec.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'default:demo:docs/dec.md',
                correlationId: 'default',
            ),
        );

        $this->assertNotSame(FlowRun::STATUS_SUCCEEDED, $run->status);

        // Engine final status: when reverse-order compensation completes
        // successfully the run is marked COMPENSATED, otherwise FAILED.
        $this->assertContains($run->status, [FlowRun::STATUS_COMPENSATED, FlowRun::STATUS_FAILED]);

        // Compensator removed the doc + chunks regardless.
        $this->assertSame(
            0,
            KnowledgeDocument::count(),
            'RollbackChunksCompensator should have force-deleted the document.',
        );
        $this->assertSame(0, KnowledgeChunk::count());

        // Persisted run reflects the compensation attempt.
        $runRow = DB::table('flow_runs')->where('id', $run->id)->first();
        $this->assertNotNull($runRow);
        $this->assertSame('maybe-dispatch-canonical-indexer', $runRow->failed_step);
    }

    /**
     * Per Copilot PR #115 review iteration 1 (fix #1): when a non-default
     * tenant dispatches the job, the queued worker must re-bind that
     * tenant before Flow::execute() runs — not silently fall back to
     * the worker's default-tenant context. The fix captures TenantContext
     * at dispatch time and re-applies it inside handle().
     */
    public function test_dispatch_for_current_tenant_propagates_tenant_id_into_queued_handle(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nTenant-bound body.");

        $tenantContext = $this->app->make(TenantContext::class);

        // Step 1: dispatcher process is on tenant-a.
        $tenantContext->set('tenant-a');
        $pending = IngestDocumentJob::dispatchForCurrentTenant(
            projectKey: 'demo',
            relativePath: 'docs/intro.md',
            disk: 'kb',
            title: 'Hello Doc',
        );

        // Step 2: simulate the queue-worker boot context — the worker
        // process always boots with the default tenant. Without the fix
        // the job would call Flow::execute() with tenant_id='default'
        // and ingest into the wrong tenant.
        $tenantContext->reset();
        $this->assertSame('default', $tenantContext->current());

        // Sync queue (configured by TestCase) executed the job inline
        // when dispatchForCurrentTenant returned, so the run already
        // happened above. The reset() above proves we are now back on
        // 'default' AFTER handle() completed.
        unset($pending);

        // Assert: the doc landed under 'tenant-a', not 'default'.
        $this->assertSame(
            1,
            KnowledgeDocument::where('tenant_id', 'tenant-a')->count(),
            'IngestDocumentJob::dispatchForCurrentTenant must propagate the tenant '
            .'captured at dispatch time so the queued worker ingests under that tenant.',
        );
        $this->assertSame(
            0,
            KnowledgeDocument::where('tenant_id', 'default')->count(),
            'Job must NOT fall back to the worker process default tenant.',
        );

        // Assert: flow_runs row was stamped with the correct tenant too —
        // both because the FlowExecutionOptions correlationId is now
        // derived from $this->tenantId and because the FlowRunRecord
        // tenant_id stamping reads TenantContext::current() inside
        // handle() AFTER our explicit re-bind.
        $tenants = DB::table('flow_runs')->pluck('tenant_id')->all();
        $this->assertSame(['tenant-a'], $tenants);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInput(string $tenantId, string $projectKey, string $sourcePath): array
    {
        return [
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'disk' => 'kb',
            'title' => 'Test Doc',
            'metadata' => [],
            'mime_type' => 'text/markdown',
        ];
    }
}
