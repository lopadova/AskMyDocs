<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Support\TenantContext;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Tests\TestCase;
use App\Models\User;

/**
 * v8.24 — GET /api/admin/connectors/{id}/folders (the folder picker's data
 * source). IMAP is the only external boundary, so a fake ImapClientFactory is
 * bound (configurable folder list / throw) and the rest runs real.
 *
 * Pin: the active tenant's own IMAP account lists its live folders (200); an
 * account with no folders is a valid 200 []; an unreachable mailbox is 503 (NOT
 * an empty 200 — R14); a cross-tenant id 404s (R30); a viewer 403s; a guest 401s.
 */
final class ImapFolderListingTest extends TestCase
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
        Cache::flush();
    }

    public function test_lists_live_folders_for_the_active_tenant_imap_account(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(folders: ['INBOX', '[Gmail]/Sent Mail', 'rotta-logistics-1']);
        $installation = $this->makeImapInstallation('default');

        $resp = $this->actingAs($admin)->getJson("/api/admin/connectors/{$installation->id}/folders");

        $resp->assertOk();
        $this->assertSame(
            ['INBOX', '[Gmail]/Sent Mail', 'rotta-logistics-1'],
            $resp->json('data.folders'),
        );
    }

    public function test_empty_folder_list_is_a_valid_200(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(folders: []);
        $installation = $this->makeImapInstallation('default');

        $resp = $this->actingAs($admin)->getJson("/api/admin/connectors/{$installation->id}/folders");

        $resp->assertOk();
        $this->assertSame([], $resp->json('data.folders'));
    }

    public function test_unreachable_mailbox_is_503_not_an_empty_200(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(throw: true);
        $installation = $this->makeImapInstallation('default');

        $resp = $this->actingAs($admin)->getJson("/api/admin/connectors/{$installation->id}/folders");

        // R14 — the caller must tell "couldn't reach the mailbox" from "no folders".
        $resp->assertStatus(503);
        $this->assertArrayHasKey('error', $resp->json());
    }

    public function test_cross_tenant_installation_is_404(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(folders: ['INBOX']);
        // The installation belongs to another tenant; active tenant is 'default'.
        $foreign = $this->makeImapInstallation('tenant-foreign');

        $this->actingAs($admin)
            ->getJson("/api/admin/connectors/{$foreign->id}/folders")
            ->assertStatus(404);
    }

    public function test_viewer_is_forbidden(): void
    {
        $viewer = $this->makeViewer();
        $this->bindImapFactory(folders: ['INBOX']);
        $installation = $this->makeImapInstallation('default');

        $this->actingAs($viewer)
            ->getJson("/api/admin/connectors/{$installation->id}/folders")
            ->assertStatus(403);
    }

    public function test_guest_is_unauthorized(): void
    {
        $installation = $this->makeImapInstallation('default');

        $this->getJson("/api/admin/connectors/{$installation->id}/folders")
            ->assertStatus(401);
    }

    private function makeImapInstallation(string $tenantId): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'imap',
            'label' => 'rotta-logistics-1',
            'project_key' => null,
            'config_json' => [
                'auth_mode' => 'basic',
                'connection' => ['host' => 'imap.example.test', 'port' => 993, 'username' => 'u@example.test'],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
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

    /**
     * @param  list<string>  $folders
     */
    private function bindImapFactory(array $folders = [], bool $throw = false): void
    {
        $client = new class($folders, $throw) implements ImapClientInterface
        {
            /** @param list<string> $folders */
            public function __construct(private readonly array $folders, private readonly bool $throw) {}

            public function listMailboxes(): array
            {
                if ($this->throw) {
                    throw new \RuntimeException('IMAP connect failed: refused');
                }

                return $this->folders;
            }

            public function ping(): bool
            {
                return true;
            }

            public function close(): void {}

            public function selectMailbox(string $name): MailboxState
            {
                return new MailboxState(uidValidity: 1, lastUid: 0);
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                return [];
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                throw new \LogicException('not used');
            }
        };

        $factory = new class($client) implements ImapClientFactoryInterface
        {
            public function __construct(private readonly ImapClientInterface $client) {}

            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return $this->client;
            }
        };

        $this->app->instance(ImapClientFactoryInterface::class, $factory);
        $this->app->forgetInstance(ConnectorRegistry::class);
    }
}
