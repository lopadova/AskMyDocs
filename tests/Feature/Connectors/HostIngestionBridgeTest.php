<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\HostIngestionBridge;
use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v4.6 — Coverage for the IoC bridge between standalone connector
 * packages and the AskMyDocs host (R30 tenant scoping, R26 PII
 * redaction opt-in, audit emission, soft-delete-by-remote-id).
 */
final class HostIngestionBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bridge_is_bound_as_singleton_via_contract(): void
    {
        $resolved = $this->app->make(ConnectorIngestionContract::class);

        $this->assertInstanceOf(HostIngestionBridge::class, $resolved);
    }

    public function test_dispatch_ingestion_queues_ingest_job_with_passed_tenant_id(): void
    {
        Queue::fake();

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $bridge->dispatchIngestion(
            projectKey: 'connector-notion',
            relativePath: 'notion/page-abc.md',
            disk: 'kb',
            title: 'Page ABC',
            metadata: ['notion_page_id' => 'abc-123'],
            mimeType: 'text/markdown',
            tenantId: 'acme',
        );

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job): bool {
            return $job->projectKey === 'connector-notion'
                && $job->relativePath === 'notion/page-abc.md'
                && $job->disk === 'kb'
                && $job->title === 'Page ABC'
                && $job->mimeType === 'text/markdown'
                && $job->tenantId === 'acme';
        });
    }

    public function test_resolve_kb_source_path_normalises_and_applies_prefix(): void
    {
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'docs');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $result = $bridge->resolveKbSourcePath('//notion//page.md');

        $this->assertSame([
            'relative' => 'notion/page.md',
            'absolute' => 'docs/notion/page.md',
            'disk' => 'kb',
        ], $result);
    }

    public function test_resolve_kb_source_path_rejects_traversal(): void
    {
        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $this->expectException(\InvalidArgumentException::class);

        $bridge->resolveKbSourcePath('../outside/kb.md');
    }

    public function test_redact_content_is_no_op_when_disabled(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_before_ingest', true);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $this->assertSame(
            'My email is user@example.com',
            $bridge->redactContent('My email is user@example.com'),
        );
    }

    public function test_redact_content_is_no_op_when_per_boundary_flag_off(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', false);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $this->assertSame(
            'My email is user@example.com',
            $bridge->redactContent('My email is user@example.com'),
        );
    }

    public function test_redact_content_masks_by_default(): void
    {
        // R43 — default ingest_strategy is the pre-v8.23 one-way mask.
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', true);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);
        $out = $bridge->redactContent('My email is user@example.com');

        $this->assertStringNotContainsString('user@example.com', $out);
        $this->assertStringNotContainsString('[tok:', $out, 'default strategy must mask, not tokenise');
    }

    public function test_redact_content_tokenises_reversibly_when_configured(): void
    {
        // v8.23 — tokenise puts a reversible surrogate in the content while the
        // original lives in the per-tenant vault (recoverable on demand).
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', true);
        config()->set('kb.pii_redactor.ingest_strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'test-salt');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);
        $out = $bridge->redactContent('My email is user@example.com');

        $this->assertStringNotContainsString('user@example.com', $out);
        $this->assertMatchesRegularExpression('/\[tok:email:[0-9a-f]+\]/', $out);

        // The original is recoverable from the shared (singleton) vault.
        $restored = $this->app
            ->make(\Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory::class)
            ->make('tokenise')
            ->detokeniseString($out);
        $this->assertStringContainsString('user@example.com', $restored);
    }

    public function test_redact_content_throws_on_unknown_ingest_strategy(): void
    {
        // R14 — unknown strategy value must throw, never silently degrade to mask.
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', true);
        config()->set('kb.pii_redactor.ingest_strategy', 'tokenize'); // common typo — missing trailing 's'

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tokenize/');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);
        $bridge->redactContent('My email is user@example.com');
    }

    public function test_emit_audit_writes_to_canonical_audit_with_namespaced_event(): void
    {
        /** @var TenantContext $ctx */
        $ctx = $this->app->make(TenantContext::class);
        $ctx->set('acme');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $bridge->emitAudit(
            connectorKey: 'notion',
            eventType: 'sync_completed',
            installationId: 42,
            metadata: ['pages' => 7],
        );

        $audit = KbCanonicalAudit::query()
            ->where('event_type', 'connector_sync_completed')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('acme', $audit->tenant_id);
        $this->assertSame('connector:notion', $audit->actor);
        $this->assertSame('notion', $audit->metadata_json['connector_key']);
        $this->assertSame(42, $audit->metadata_json['installation_id']);
        $this->assertSame(['pages' => 7], $audit->metadata_json['metadata']);
    }

    public function test_emit_audit_does_not_double_namespace_when_caller_passes_namespaced_event(): void
    {
        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $bridge->emitAudit(
            connectorKey: 'google-drive',
            eventType: 'connector_installed',
            installationId: 7,
            metadata: null,
        );

        $this->assertTrue(
            KbCanonicalAudit::query()
                ->where('event_type', 'connector_installed')
                ->exists(),
        );
        $this->assertFalse(
            KbCanonicalAudit::query()
                ->where('event_type', 'connector_connector_installed')
                ->exists(),
        );
    }

    public function test_soft_delete_by_remote_id_routes_through_document_deleter(): void
    {
        /** @var TenantContext $ctx */
        $ctx = $this->app->make(TenantContext::class);
        $ctx->set('acme');

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);

        $doc = KnowledgeDocument::create([
            'tenant_id' => 'acme',
            'project_key' => 'connector-notion',
            'source_type' => 'markdown',
            'title' => 'Page A',
            'source_path' => 'notion/page-a.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('a', 64),
            'is_canonical' => false,
            'metadata' => ['notion_page_id' => 'page-a-uuid'],
        ]);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $acted = $bridge->softDeleteByRemoteId(
            installation: $installation,
            metadataKey: 'notion_page_id',
            remoteId: 'page-a-uuid',
        );

        $this->assertTrue($acted);
        $this->assertSoftDeleted($doc->fresh());
    }

    public function test_soft_delete_by_remote_id_returns_false_when_no_match(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $acted = $bridge->softDeleteByRemoteId(
            installation: $installation,
            metadataKey: 'notion_page_id',
            remoteId: 'no-such-uuid',
        );

        $this->assertFalse($acted);
    }

    public function test_soft_delete_by_remote_id_is_tenant_scoped(): void
    {
        // Tenant A owns the installation.
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);

        // Tenant B happens to own a document with the SAME remote-id.
        // (Two different Notion workspaces COULD legitimately collide on
        // a page-id if Notion ever recycled UUIDs — and even if it never
        // does, the tenant boundary is the only safe scope per R30.)
        $tenantBDoc = KnowledgeDocument::create([
            'tenant_id' => 'tenant-b',
            'project_key' => 'connector-notion',
            'source_type' => 'markdown',
            'title' => 'Tenant B page',
            'source_path' => 'notion/page-shared.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('b', 64),
            'version_hash' => str_repeat('b', 64),
            'is_canonical' => false,
            'metadata' => ['notion_page_id' => 'shared-uuid'],
        ]);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $acted = $bridge->softDeleteByRemoteId(
            installation: $installation,
            metadataKey: 'notion_page_id',
            remoteId: 'shared-uuid',
        );

        $this->assertFalse(
            $acted,
            'softDeleteByRemoteId must NEVER cross tenant boundaries (R30).',
        );

        // Tenant B's doc must remain untouched.
        $this->assertNotSoftDeleted('knowledge_documents', [
            'id' => $tenantBDoc->id,
            'tenant_id' => 'tenant-b',
        ]);
    }

    public function test_soft_delete_by_remote_id_is_idempotent_on_already_trashed_rows(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);

        $doc = KnowledgeDocument::create([
            'tenant_id' => 'acme',
            'project_key' => 'connector-notion',
            'source_type' => 'markdown',
            'title' => 'Already trashed',
            'source_path' => 'notion/trashed.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('c', 64),
            'version_hash' => str_repeat('c', 64),
            'is_canonical' => false,
            'metadata' => ['notion_page_id' => 'trashed-uuid'],
        ]);

        // Pre-soft-delete the row to simulate a prior sweep that
        // already acted on it.
        (new DocumentDeleter)->delete($doc, force: false);
        $doc->refresh();

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $acted = $bridge->softDeleteByRemoteId(
            installation: $installation,
            metadataKey: 'notion_page_id',
            remoteId: 'trashed-uuid',
        );

        // Idempotent: no work performed on already-trashed rows.
        $this->assertFalse($acted);
    }
}
