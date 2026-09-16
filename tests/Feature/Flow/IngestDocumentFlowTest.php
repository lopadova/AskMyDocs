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
            $this->buildInput('test-tenant', 'demo', 'docs/intro.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'test-tenant:demo:docs/intro.md',
                correlationId: 'test-tenant',
            ),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, KnowledgeDocument::count());

        // Persisted Flow rows live alongside the run id.
        $runRow = DB::table('flow_runs')->where('id', $run->id)->first();
        $this->assertNotNull($runRow);
        $this->assertSame('test-tenant', $runRow->tenant_id);
        $this->assertSame('succeeded', $runRow->status);
        $this->assertSame(IngestDocumentFlow::NAME, $runRow->definition_name);

        $stepRows = DB::table('flow_run_nodes')
            ->where('run_id', $run->id)
            ->orderBy('sequence')
            ->get();
        $stepNames = $stepRows->pluck('node_id')->all();
        $this->assertSame([
            'parse-markdown',
            'chunk-document',
            'embed-chunks',
            'persist-chunks',
            'maybe-dispatch-canonical-indexer',
            'maybe-dispatch-collections-evaluator',
        ], $stepNames);
        foreach ($stepRows as $stepRow) {
            // The tenant is what the host decorator adds; node_type is what
            // v2 requires and the engine supplies. Asserting both means a
            // regression in either the decorator or the compiled-step path
            // fails here rather than surfacing later as an untenanted or
            // untyped row the schema happens to accept.
            $this->assertSame('test-tenant', $stepRow->tenant_id);
            $this->assertSame('legacy.step', $stepRow->node_type);
        }

        $auditCount = DB::table('flow_audit')->where('run_id', $run->id)->count();
        $this->assertGreaterThan(0, $auditCount);
    }

    /**
     * ADR 0030 §3 — on the Flow saga a refused artifact publish fails the
     * `persist-chunks` step AFTER the row committed: the row and its pointer
     * stay (state `missing`, never rolled back — compensators fire only for
     * downstream failures), the indexer step of THAT attempt does not run,
     * and the retry — a new run under the attempt-salted key, an identical
     * re-ingest — repairs the artifact and dispatches the indexer.
     */
    public function test_a_refused_artifact_publish_fails_the_persist_step_keeps_the_row_and_the_retry_repairs_and_indexes(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('decisions/dec-flow-refused.md', "---\nid: DEC-2026-0778\nslug: dec-flow-refused\ntype: decision\nstatus: accepted\n---\n\n# Refused on the saga\n\nStill a decision.\n");
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_contains($path, '.versions/') && str_ends_with($path, '.md'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));
        $job = new IngestDocumentJob('demo', 'decisions/dec-flow-refused.md', 'kb', tenantId: 'test-tenant');

        $first = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('test-tenant', 'demo', 'decisions/dec-flow-refused.md'),
            FlowExecutionOptions::make(idempotencyKey: $job->idempotencyKeyFor('test-tenant', 1), correlationId: 'test-tenant'),
        );

        $this->assertNotSame(FlowRun::STATUS_SUCCEEDED, $first->status);
        $this->assertSame('persist-chunks', $first->failedStep);
        $doc = KnowledgeDocument::withoutGlobalScopes()->where('tenant_id', 'test-tenant')->where('source_path', 'decisions/dec-flow-refused.md')->first();
        $this->assertNotNull($doc, 'the row committed before the publish and is never rolled back');
        $this->assertTrue((bool) $doc->is_canonical);
        $this->assertNotNull($doc->markdown_path, 'the pointer is kept as the repairable `missing` state');
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\CanonicalIndexerJob::class);

        Storage::set('kb', $healthy);
        $retry = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('test-tenant', 'demo', 'decisions/dec-flow-refused.md'),
            FlowExecutionOptions::make(idempotencyKey: $job->idempotencyKeyFor('test-tenant', 2), correlationId: 'test-tenant'),
        );

        $this->assertNotSame($first->id, $retry->id, 'a retry is a new run: the failed run is not handed back');
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $retry->status);
        $this->assertSame(1, KnowledgeDocument::withoutGlobalScopes()->where('tenant_id', 'test-tenant')->count(), 'the retry is an identical re-ingest, not a second version');
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CanonicalIndexerJob::class, static fn (\App\Jobs\CanonicalIndexerJob $pushed): bool => (int) $pushed->documentId === (int) $doc->id);
    }

    public function test_idempotency_returns_existing_run_on_redispatch(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nFirst paragraph.");

        $first = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('test-tenant', 'demo', 'docs/intro.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'test-tenant:demo:docs/intro.md',
                correlationId: 'test-tenant',
            ),
        );

        $second = Flow::execute(
            IngestDocumentFlow::NAME,
            $this->buildInput('test-tenant', 'demo', 'docs/intro.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'test-tenant:demo:docs/intro.md',
                correlationId: 'test-tenant',
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
                idempotencyKey: 'shared-ingest-key',
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
                idempotencyKey: 'shared-ingest-key',
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
            $this->buildInput('test-tenant', 'demo', 'docs/dec.md'),
            FlowExecutionOptions::make(
                idempotencyKey: 'test-tenant:demo:docs/dec.md',
                correlationId: 'test-tenant',
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
        // the job would call Flow::execute() with the worker's test tenant
        // and ingest into the wrong tenant.
        $tenantContext->reset();
        $this->assertSame('test-tenant', $tenantContext->current());

        // Sync queue (configured by TestCase) executed the job inline
        // when dispatchForCurrentTenant returned, so the run already
        // happened above. The reset() above proves we are now back on
        // 'test-tenant' AFTER handle() completed.
        unset($pending);

        // Assert: the doc landed under 'tenant-a', not the worker tenant.
        $this->assertSame(
            1,
            KnowledgeDocument::where('tenant_id', 'tenant-a')->count(),
            'IngestDocumentJob::dispatchForCurrentTenant must propagate the tenant '
            .'captured at dispatch time so the queued worker ingests under that tenant.',
        );
        $this->assertSame(
            0,
            KnowledgeDocument::where('tenant_id', 'test-tenant')->count(),
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
