<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Tests\TestCase;

/**
 * v8.20 — the PHP surface (R44) of the multi-account connector capability:
 * `connectors:list` (read core) + `connectors:install` (interactive credential
 * install). Both over the SAME core as the HTTP + MCP surfaces.
 */
final class ConnectorsCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_connectors_list_shows_accounts_for_the_tenant(): void
    {
        foreach (['support', 'sales'] as $label) {
            ConnectorInstallation::create([
                'tenant_id' => 'default',
                'connector_name' => 'google-drive',
                'label' => $label,
                'status' => ConnectorInstallation::STATUS_ACTIVE,
            ]);
        }

        $this->artisan('connectors:list', ['--tenant' => 'default'])
            ->expectsOutputToContain('support')
            ->expectsOutputToContain('sales')
            ->assertExitCode(0);
    }

    public function test_connectors_list_is_tenant_scoped(): void
    {
        ConnectorInstallation::create([
            'tenant_id' => 'tenant-foreign',
            'connector_name' => 'google-drive',
            'label' => 'secret-account',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->artisan('connectors:list', ['--tenant' => 'default'])
            ->doesntExpectOutputToContain('secret-account')
            ->assertExitCode(0);
    }

    public function test_connectors_install_credential_account_with_project_binding(): void
    {
        $this->bindImapFactory(pingSucceeds: true);
        Project::create(['project_key' => 'support-mailbox', 'name' => 'Support Mailbox']);

        $this->artisan('connectors:install', [
            'connector' => 'imap',
            '--tenant' => 'default',
            '--label' => 'Support',
            '--project' => 'support-mailbox',
            '--set' => [
                'host=imap.example.com',
                'port=993',
                'encryption=ssl',
                'validate_cert=1',
                'username=alice@example.com',
            ],
        ])
            ->expectsQuestion('Password', 's3cr3t-app-pw')
            ->assertExitCode(0);

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', 'default')->where('connector_name', 'imap')->firstOrFail();
        $this->assertSame('Support', $installation->label);
        $this->assertSame('support-mailbox', $installation->project_key);
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $installation->status);
        // Secret vaulted, never in config_json.
        $this->assertSame('s3cr3t-app-pw', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
        $this->assertArrayNotHasKey('project_key', $installation->config_json);
    }

    public function test_connectors_install_rejects_a_duplicate_label(): void
    {
        $this->bindImapFactory(pingSucceeds: true);
        ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'Support',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->artisan('connectors:install', [
            'connector' => 'imap',
            '--tenant' => 'default',
            '--label' => 'Support',
            '--set' => [
                'host=imap.example.com',
                'port=993',
                'encryption=ssl',
                'validate_cert=1',
                'username=alice@example.com',
            ],
        ])
            ->expectsQuestion('Password', 's3cr3t-app-pw')
            ->assertExitCode(1);

        $this->assertSame(
            1,
            ConnectorInstallation::query()->where('connector_name', 'imap')->count(),
        );
    }

    public function test_connectors_install_rejects_an_unknown_project(): void
    {
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connectors:install', [
            'connector' => 'imap',
            '--tenant' => 'default',
            '--label' => 'Support',
            '--project' => 'does-not-exist',
        ])
            ->assertExitCode(1);

        $this->assertSame(0, ConnectorInstallation::query()->count());
    }

    public function test_connectors_install_rejects_an_invalid_label(): void
    {
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connectors:install', [
            'connector' => 'imap',
            '--tenant' => 'default',
            '--label' => 'bad/label',
        ])
            ->expectsOutputToContain('Invalid --label')
            ->assertExitCode(1);

        $this->assertSame(0, ConnectorInstallation::query()->count());
    }

    public function test_connectors_install_refuses_oauth_connectors(): void
    {
        $this->artisan('connectors:install', [
            'connector' => 'google-drive',
            '--tenant' => 'default',
        ])
            ->expectsOutputToContain('OAuth-based')
            ->assertExitCode(1);
    }

    private function bindImapFactory(bool $pingSucceeds): void
    {
        $client = new class($pingSucceeds) implements ImapClientInterface
        {
            public function __construct(private readonly bool $ok) {}

            public function ping(): bool
            {
                return $this->ok;
            }

            public function close(): void {}

            public function listMailboxes(): array
            {
                throw new \LogicException('not used in configure');
            }

            public function selectMailbox(string $name): MailboxState
            {
                throw new \LogicException('not used in configure');
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                throw new \LogicException('not used in configure');
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                throw new \LogicException('not used in configure');
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
