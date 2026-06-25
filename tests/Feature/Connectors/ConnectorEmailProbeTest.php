<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Tests\TestCase;

/**
 * POST /api/admin/connectors/{id}/test-fetch — the "download one email, no ingest"
 * diagnostic. IMAP is the only external boundary, so a fake ImapClientFactory is
 * bound (configurable newest-uid / message / throw); the rest runs real.
 *
 * Pins: a reachable folder returns the newest message's sanitized preview WITHOUT
 * ingesting (no queued ingest job — R14/R44 the no-call short-circuit); an empty
 * folder is a valid 200 with message:null (R43 other state); an unreachable mailbox
 * is 503 (NOT an empty 200 — R14); a non-IMAP / cross-tenant id 404s (R30); a viewer
 * 403s; a guest 401s.
 */
final class ConnectorEmailProbeTest extends TestCase
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

    public function test_returns_the_newest_message_preview_without_ingesting(): void
    {
        Queue::fake();
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(lastUid: 4211, message: $this->sampleMessage(4211));
        $installation = $this->makeImapInstallation('default');

        $resp = $this->actingAs($admin)->postJson("/api/admin/connectors/{$installation->id}/test-fetch");

        $resp->assertOk();
        $this->assertSame('INBOX', $resp->json('data.folder'));
        $this->assertSame(4211, $resp->json('data.message.uid'));
        $this->assertSame('Allarme zona 3', $resp->json('data.message.subject'));
        $this->assertSame('noreply@prometeo.test', $resp->json('data.message.from_email'));
        $this->assertStringContainsString('rilevatore', $resp->json('data.message.snippet'));

        // The whole point of "test fetch": it must NOT run the ingest pipeline.
        Queue::assertNothingPushed();
    }

    public function test_probes_the_first_whitelisted_folder_when_set(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(lastUid: 7, message: $this->sampleMessage(7));
        $installation = $this->makeImapInstallation('default', include: ['rotta-logistics-1', 'INBOX']);

        $resp = $this->actingAs($admin)->postJson("/api/admin/connectors/{$installation->id}/test-fetch");

        $resp->assertOk();
        $this->assertSame('rotta-logistics-1', $resp->json('data.folder'));
    }

    public function test_empty_folder_is_a_valid_200_with_null_message(): void
    {
        // R43 — the OTHER state: a reachable but empty mailbox is a success, not a
        // failure; the FE shows a "connected, nothing to preview" notice.
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(lastUid: 0, message: null);
        $installation = $this->makeImapInstallation('default');

        $resp = $this->actingAs($admin)->postJson("/api/admin/connectors/{$installation->id}/test-fetch");

        $resp->assertOk();
        $this->assertSame('INBOX', $resp->json('data.folder'));
        $this->assertNull($resp->json('data.message'));
    }

    public function test_unreachable_mailbox_is_503_not_an_empty_200(): void
    {
        // R14 — the caller must tell "couldn't connect" from "no messages".
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(lastUid: 1, message: null, throw: true);
        $installation = $this->makeImapInstallation('default');

        $resp = $this->actingAs($admin)->postJson("/api/admin/connectors/{$installation->id}/test-fetch");

        $resp->assertStatus(503);
        $this->assertArrayHasKey('error', $resp->json());
    }

    public function test_missing_credentials_is_503_with_a_clear_message(): void
    {
        // No ConnectorCredential row → the vault has no secret → the probe cannot run.
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(lastUid: 1, message: $this->sampleMessage(1));
        $installation = $this->makeImapInstallation('default', withCredential: false);

        $resp = $this->actingAs($admin)->postJson("/api/admin/connectors/{$installation->id}/test-fetch");

        $resp->assertStatus(503);
        $this->assertStringContainsString('credential', strtolower((string) $resp->json('error')));
    }

    public function test_non_imap_connector_is_404(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(lastUid: 1, message: $this->sampleMessage(1));
        $drive = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'label' => 'default',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/connectors/{$drive->id}/test-fetch")
            ->assertStatus(404);
    }

    public function test_cross_tenant_installation_is_404(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->bindImapFactory(lastUid: 1, message: $this->sampleMessage(1));
        $foreign = $this->makeImapInstallation('tenant-foreign');

        $this->actingAs($admin)
            ->postJson("/api/admin/connectors/{$foreign->id}/test-fetch")
            ->assertStatus(404);
    }

    public function test_viewer_is_forbidden(): void
    {
        $viewer = $this->makeViewer();
        $this->bindImapFactory(lastUid: 1, message: $this->sampleMessage(1));
        $installation = $this->makeImapInstallation('default');

        $this->actingAs($viewer)
            ->postJson("/api/admin/connectors/{$installation->id}/test-fetch")
            ->assertStatus(403);
    }

    public function test_guest_is_unauthorized(): void
    {
        $installation = $this->makeImapInstallation('default');

        $this->postJson("/api/admin/connectors/{$installation->id}/test-fetch")
            ->assertStatus(401);
    }

    /**
     * @param  list<string>  $include
     */
    private function makeImapInstallation(
        string $tenantId,
        array $include = [],
        bool $withCredential = true,
    ): ConnectorInstallation {
        $installation = ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'imap',
            'label' => 'prometeo-1',
            'project_key' => null,
            'config_json' => [
                'auth_mode' => 'basic',
                'connection' => ['host' => 'imap.example.test', 'port' => 993, 'username' => 'u@example.test'],
                'folders' => ['include' => $include],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        if ($withCredential) {
            ConnectorCredential::create([
                'tenant_id' => $tenantId,
                'connector_installation_id' => $installation->id,
                'encrypted_access_token' => Crypt::encryptString('app-password'),
            ]);
        }

        return $installation;
    }

    private function sampleMessage(int $uid): ImapMessage
    {
        return new ImapMessage(
            uid: $uid,
            uidValidity: 1,
            mailbox: 'INBOX',
            messageId: '<msg-'.$uid.'@prometeo.test>',
            inReplyTo: null,
            references: [],
            fromName: 'Centrale Prometeo',
            fromEmail: 'noreply@prometeo.test',
            to: [['name' => 'Ops', 'email' => 'ops@cliente.test']],
            cc: [],
            date: Carbon::parse('2026-06-24T08:15:00Z'),
            subject: 'Allarme zona 3',
            flags: [],
            labels: [],
            textBody: "Il rilevatore della zona 3 ha segnalato un evento.\n\nControllare al più presto.",
            htmlBody: null,
            rawHeaders: [],
            attachments: [],
        );
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

    private function bindImapFactory(int $lastUid, ?ImapMessage $message, bool $throw = false): void
    {
        $client = new class($lastUid, $message, $throw) implements ImapClientInterface
        {
            public function __construct(
                private readonly int $lastUid,
                private readonly ?ImapMessage $message,
                private readonly bool $throw,
            ) {}

            public function listMailboxes(): array
            {
                return ['INBOX'];
            }

            public function selectMailbox(string $name): MailboxState
            {
                if ($this->throw) {
                    throw new \RuntimeException('IMAP connect failed: refused');
                }

                return new MailboxState(uidValidity: 1, lastUid: $this->lastUid);
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                return $this->lastUid > 0 ? [$this->lastUid] : [];
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                if ($this->message === null) {
                    throw new \LogicException('no message configured');
                }

                return $this->message;
            }

            public function ping(): bool
            {
                return true;
            }

            public function close(): void {}
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
    }
}
