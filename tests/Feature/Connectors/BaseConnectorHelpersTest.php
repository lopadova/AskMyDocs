<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Auth\OAuthCredentialVault;
use App\Connectors\BaseConnector;
use App\Connectors\HealthStatus;
use App\Connectors\SyncResult;
use App\Models\ConnectorInstallation;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Padosoft\PiiRedactor\RedactorEngine;
use Tests\TestCase;

/**
 * v4.5/W2 — BaseConnector::softDeleteByMetadataKey() and
 * BaseConnector::resolveKbSourcePath() tests.
 *
 * Both helpers were extracted from the W1 Google Drive connector so
 * subsequent connectors (Notion, OneDrive, ...) don't reinvent the
 * lookup-by-metadata + path-prefix conventions and accidentally drift.
 *
 * The helpers are protected, so we test them through a tiny anonymous
 * subclass that exposes them as public proxies. This keeps the
 * production surface minimal while keeping the regression coverage
 * meaningful.
 */
final class BaseConnectorHelpersTest extends TestCase
{
    use RefreshDatabase;

    private function harness(): BaseConnector
    {
        $vault = $this->app->make(OAuthCredentialVault::class);
        $tenantContext = $this->app->make(TenantContext::class);
        $redactor = $this->app->make(RedactorEngine::class);

        return new class($vault, $tenantContext, $redactor) extends BaseConnector
        {
            public function key(): string
            {
                return 'helper-harness';
            }

            public function displayName(): string
            {
                return 'Helper Harness';
            }

            public function initiateOAuth(int $installationId): string
            {
                return 'https://example.test/auth';
            }

            public function handleOAuthCallback(int $installationId, Request $request): void {}

            public function syncFull(int $installationId): SyncResult
            {
                return SyncResult::empty();
            }

            public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
            {
                return SyncResult::empty();
            }

            public function disconnect(int $installationId): void {}

            public function health(int $installationId): HealthStatus
            {
                return HealthStatus::healthy();
            }

            public function publicSoftDeleteByMetadataKey(
                ConnectorInstallation $installation,
                string $metadataKey,
                string $remoteId,
            ): bool {
                return $this->softDeleteByMetadataKey($installation, $metadataKey, $remoteId);
            }

            /**
             * @return array{relative: string, absolute: string, disk: string}
             */
            public function publicResolveKbSourcePath(string $relativePath): array
            {
                return $this->resolveKbSourcePath($relativePath);
            }
        };
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'helper-harness',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);
    }

    private function seedDocument(string $tenantId, string $metadataKey, string $remoteId): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => 'connector-helper-harness',
            'source_type' => 'markdown',
            'title' => 'doc '.$remoteId,
            'source_path' => 'connector-helper-harness/'.$remoteId.'.md',
            'mime_type' => 'text/markdown',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [
                'connector' => 'helper-harness',
                $metadataKey => $remoteId,
            ],
        ]);
    }

    public function test_softDeleteByMetadataKey_finds_and_deletes_when_match(): void
    {
        $installation = $this->makeInstallation();
        $doc = $this->seedDocument($installation->tenant_id, 'notion_page_id', 'page-xyz');

        $result = $this->harness()->publicSoftDeleteByMetadataKey(
            $installation,
            'notion_page_id',
            'page-xyz',
        );

        $this->assertTrue($result);
        $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
    }

    public function test_softDeleteByMetadataKey_returns_false_when_no_match(): void
    {
        $installation = $this->makeInstallation();
        // Seed a document with DIFFERENT remote id — must not match.
        $this->seedDocument($installation->tenant_id, 'notion_page_id', 'page-other');

        $result = $this->harness()->publicSoftDeleteByMetadataKey(
            $installation,
            'notion_page_id',
            'never-seen-page',
        );

        $this->assertFalse($result);
    }

    public function test_softDeleteByMetadataKey_respects_tenant_isolation(): void
    {
        $installationA = $this->makeInstallation('tenant-a');
        // Same remote id under tenant B — MUST NOT be touched.
        $docB = $this->seedDocument('tenant-b', 'notion_page_id', 'shared-page-id');

        $this->app->make(TenantContext::class)->set('tenant-a');
        $result = $this->harness()->publicSoftDeleteByMetadataKey(
            $installationA,
            'notion_page_id',
            'shared-page-id',
        );
        $this->app->make(TenantContext::class)->reset();

        // No document in tenant-a matches → false return.
        $this->assertFalse($result);
        // Tenant-B's document is untouched.
        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $docB->id,
            'deleted_at' => null,
        ]);
    }

    public function test_softDeleteByMetadataKey_skips_already_trashed_rows(): void
    {
        $installation = $this->makeInstallation();
        $doc = $this->seedDocument($installation->tenant_id, 'notion_page_id', 'page-trashed');
        $doc->delete(); // already soft-deleted

        $result = $this->harness()->publicSoftDeleteByMetadataKey(
            $installation,
            'notion_page_id',
            'page-trashed',
        );

        // Already trashed → the helper finds the row but does not
        // re-delete; returns false so the counter stays honest.
        $this->assertFalse($result);
    }

    public function test_resolveKbSourcePath_applies_configured_prefix(): void
    {
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'tenant-prefix');

        $paths = $this->harness()->publicResolveKbSourcePath('project-x/connectors/notion/ws/page.md');

        $this->assertSame('kb', $paths['disk']);
        $this->assertSame('project-x/connectors/notion/ws/page.md', $paths['relative']);
        $this->assertSame('tenant-prefix/project-x/connectors/notion/ws/page.md', $paths['absolute']);
    }

    public function test_resolveKbSourcePath_returns_both_relative_and_absolute_forms(): void
    {
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $paths = $this->harness()->publicResolveKbSourcePath('project-x/doc.md');

        $this->assertSame('project-x/doc.md', $paths['relative']);
        // With empty prefix, absolute == relative.
        $this->assertSame('project-x/doc.md', $paths['absolute']);
    }
}
