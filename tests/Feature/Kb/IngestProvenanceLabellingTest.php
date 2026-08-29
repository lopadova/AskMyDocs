<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\ConnectorInterface;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Tests\TestCase;

/**
 * ADR 0028 phase 1 — the label has to survive the ingestion path, not just
 * the resolver.
 *
 * The resolver's own unit test proves it asks the right question. This one
 * proves the answer reaches the column that retrieval and the read-out both
 * depend on, through the single choke point every ingestion path shares.
 */
final class IngestProvenanceLabellingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kb');
        Queue::fake();

        // The tier is what is under test; embedding is not. A stub keeps the
        // ingestion path off the network without touching the code path that
        // writes the column.
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            static fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(static fn () => array_fill(0, 3, 0.1), array_values($texts)),
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        // R41 — the RefreshDatabase rollback has to happen before anything
        // that can throw.
        parent::tearDown();
        Mockery::close();
    }

    private function registryReturning(mixed $connector): void
    {
        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->andReturn($connector);

        $this->app->instance(ConnectorRegistry::class, $registry);
        // The resolver is constructed from the container inside the ingestor,
        // so rebinding the registry is enough to steer it.
        $this->app->forgetInstance(\App\Services\Kb\Provenance\ProvenanceResolver::class);
    }

    private function declaring(ProvenanceTier $tier): ConnectorInterface
    {
        $connector = Mockery::mock(ConnectorInterface::class, DeclaresProvenance::class);
        $connector->shouldReceive('provenanceTier')->andReturn($tier);

        /** @var ConnectorInterface $connector */
        return $connector;
    }

    public function test_a_declared_tier_is_persisted_on_the_document(): void
    {
        $this->registryReturning($this->declaring(ProvenanceTier::UntrustedExternal));

        $doc = app(DocumentIngestor::class)->ingestMarkdown(
            projectKey: 'support',
            sourcePath: 'mail/thread-1.md',
            title: 'Re: invoice question',
            markdown: "# Re: invoice question\n\nCould you resend the invoice?",
            metadata: ['connector' => 'imap', 'installation_id' => 3],
        );

        $this->assertSame(ProvenanceTier::UntrustedExternal->value, $doc->fresh()->provenance_tier);
    }

    public function test_ingestion_without_a_connector_leaves_the_column_undeclared(): void
    {
        // The CLI walker and the HTTP batch endpoint carry no connector key.
        // Writing a tier there would be inventing a fact.
        $this->registryReturning(null);

        $doc = app(DocumentIngestor::class)->ingestMarkdown(
            projectKey: 'engineering',
            sourcePath: 'docs/runbook.md',
            title: 'Runbook',
            markdown: "# Runbook\n\nRestart the worker.",
        );

        $this->assertNull($doc->fresh()->provenance_tier);
    }

    public function test_a_connector_that_declares_nothing_leaves_the_column_undeclared(): void
    {
        // A connector predating the capability must keep its exact current
        // meaning rather than being labelled by the host on its behalf.
        $silent = Mockery::mock(ConnectorInterface::class);
        $this->registryReturning($silent);

        $doc = app(DocumentIngestor::class)->ingestMarkdown(
            projectKey: 'engineering',
            sourcePath: 'wiki/page.md',
            title: 'Page',
            markdown: "# Page\n\nContent.",
            metadata: ['connector' => 'notion', 'installation_id' => 1],
        );

        $this->assertNull($doc->fresh()->provenance_tier);
    }
}
