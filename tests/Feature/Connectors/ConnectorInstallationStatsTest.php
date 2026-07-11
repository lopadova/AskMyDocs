<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v8.31 — GET /api/admin/connectors/{id}/stats: the per-account stats for the
 * Edit → Details tab of the redesigned connector modal.
 *
 * Pin: the count is documents whose `metadata->installation_id` matches AND that
 * are live + in the active tenant — it must NOT count another installation's docs,
 * another tenant's docs, or soft-deleted docs (R30 + soft-delete awareness). A
 * cross-tenant id 404s; a viewer 403s; a guest 401s.
 */
final class ConnectorInstallationStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    public function test_counts_only_this_installations_live_tenant_documents(): void
    {
        $admin = $this->makeSuperAdmin();
        $installation = $this->makeInstallation('default', 'Support');
        $other = $this->makeInstallation('default', 'Sales');

        // 2 live docs for this installation.
        $this->makeDoc('default', $installation->id, 'a');
        $this->makeDoc('default', $installation->id, 'b');
        // A soft-deleted doc for this installation — must NOT be counted.
        $this->makeDoc('default', $installation->id, 'c')->delete();
        // Another installation's doc — must NOT be counted.
        $this->makeDoc('default', $other->id, 'd');
        // Another tenant's doc for this same installation id — must NOT be counted (R30).
        $this->makeDoc('acme', $installation->id, 'e');

        $resp = $this->actingAs($admin)->getJson("/api/admin/connectors/{$installation->id}/stats");

        $resp->assertOk();
        $this->assertSame(2, $resp->json('data.documents_synced'));
        $this->assertArrayHasKey('last_sync_at', $resp->json('data'));
    }

    public function test_zero_documents_is_a_valid_200(): void
    {
        $admin = $this->makeSuperAdmin();
        $installation = $this->makeInstallation('default', 'Fresh');

        $resp = $this->actingAs($admin)->getJson("/api/admin/connectors/{$installation->id}/stats");

        $resp->assertOk();
        $this->assertSame(0, $resp->json('data.documents_synced'));
    }

    public function test_cross_tenant_installation_id_404s(): void
    {
        $admin = $this->makeSuperAdmin(); // acts under 'default'
        $foreign = $this->makeInstallation('acme', 'Foreign');
        $this->makeDoc('acme', $foreign->id, 'a');

        $this->actingAs($admin)
            ->getJson("/api/admin/connectors/{$foreign->id}/stats")
            ->assertNotFound();
    }

    public function test_viewer_is_forbidden_and_guest_unauthorized(): void
    {
        $installation = $this->makeInstallation('default', 'Support');

        $this->getJson("/api/admin/connectors/{$installation->id}/stats")->assertUnauthorized();

        $viewer = $this->makeViewer();
        $this->actingAs($viewer)
            ->getJson("/api/admin/connectors/{$installation->id}/stats")
            ->assertForbidden();
    }

    private function makeInstallation(string $tenantId, string $label): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'imap',
            'label' => $label,
            'project_key' => null,
            'config_json' => ['auth_mode' => 'basic'],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
    }

    private function makeDoc(string $tenantId, int $installationId, string $seed): KnowledgeDocument
    {
        $hash = str_pad($seed, 64, $seed);

        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => 'connector-imap',
            'source_type' => 'markdown',
            'title' => "Email {$seed}",
            'source_path' => "imap/{$seed}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => $hash,
            'version_hash' => $hash,
            'is_canonical' => false,
            'metadata' => ['connector' => 'imap', 'installation_id' => $installationId],
        ]);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function makeViewer(): User
    {
        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        return $user;
    }
}
