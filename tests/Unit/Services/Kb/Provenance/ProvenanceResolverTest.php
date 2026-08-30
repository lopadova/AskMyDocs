<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Provenance;

use App\Services\Kb\Provenance\ProvenanceResolver;
use Mockery;
use Mockery\MockInterface;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\ConnectorInterface;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use RuntimeException;
use Tests\TestCase;

final class ProvenanceResolverTest extends TestCase
{
    /**
     * `ConnectorRegistry::get()` returns `?ConnectorInterface`, so a double
     * has to satisfy that type as well as the capability under test.
     */
    private function declaringConnector(): MockInterface
    {
        return Mockery::mock(ConnectorInterface::class, DeclaresProvenance::class);
    }

    private function resolverWith(mixed $connector, string $name = 'imap'): ProvenanceResolver
    {
        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with($name)->andReturn($connector);
        $registry->shouldReceive('get')->andReturn(null);

        return new ProvenanceResolver($registry);
    }

    public function test_it_asks_the_connector_for_the_tier(): void
    {
        $connector = $this->declaringConnector();
        $connector->shouldReceive('provenanceTier')->once()->with(7)
            ->andReturn(ProvenanceTier::UntrustedExternal);

        $resolved = $this->resolverWith($connector)->forInstallation('imap', 7);

        $this->assertSame(ProvenanceTier::UntrustedExternal, $resolved);
    }

    public function test_a_connector_that_declares_nothing_yields_null(): void
    {
        // Not an error and not a tier: "no declaration". The column stays
        // null and readers resolve it to the default, so a connector that
        // predates the capability keeps its exact current meaning.
        $silent = Mockery::mock(ConnectorInterface::class);

        $this->assertNull($this->resolverWith($silent)->forInstallation('imap', 7));
    }

    public function test_missing_connector_context_yields_null(): void
    {
        $resolver = $this->resolverWith(null, 'nope');

        $this->assertNull($resolver->forInstallation(null, 7));
        $this->assertNull($resolver->forInstallation('', 7));
        $this->assertNull($resolver->forInstallation('imap', null));
    }

    public function test_a_throwing_connector_fails_closed_rather_than_trusted(): void
    {
        // A broken declaration must not promote content. Returning null here
        // would read as the trusted default, which is the fail-open this
        // whole design exists to avoid; propagating would fail a document's
        // ingestion over a label that nothing enforces yet.
        $broken = $this->declaringConnector();
        $broken->shouldReceive('provenanceTier')->andThrow(new RuntimeException('upstream config missing'));

        $resolved = $this->resolverWith($broken)->forInstallation('imap', 7);

        $this->assertSame(ProvenanceTier::UntrustedExternal, $resolved);
        $this->assertTrue($resolved->isExternallyAuthored());
    }

    public function test_it_reads_connector_context_out_of_ingestion_metadata(): void
    {
        $connector = $this->declaringConnector();
        // The installation id has to survive the trip as an int, not the
        // string a queued payload hands back.
        $connector->shouldReceive('provenanceTier')->once()->with(42)
            ->andReturn(ProvenanceTier::UntrustedExternal);

        $resolved = $this->resolverWith($connector)
            ->forIngestionMetadata(['connector' => 'imap', 'installation_id' => '42']);

        $this->assertSame(ProvenanceTier::UntrustedExternal, $resolved);
    }

    public function test_non_connector_ingestion_is_undeclared(): void
    {
        // The CLI walker, the HTTP batch endpoint and the GitHub action carry
        // no connector key. That content was placed there by someone with
        // access to the repository or the API, so "no declaration" is the
        // honest answer rather than a guess.
        $resolver = $this->resolverWith(null, 'nope');

        $this->assertNull($resolver->forIngestionMetadata([]));
        $this->assertNull($resolver->forIngestionMetadata(['connector' => 'imap']));
        $this->assertNull($resolver->forIngestionMetadata(['installation_id' => 3]));
    }
}
