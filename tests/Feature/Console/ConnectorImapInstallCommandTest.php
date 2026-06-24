<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Tests\TestCase;

/**
 * Feature test di `connector:imap:install` — modello v8.20 MULTI-ACCOUNT.
 * L'IMAP è il solo confine esterno: si binda un fake ImapClientFactory (ping
 * configurabile) come in ConfigureConnectorTest, così configure() gira offline.
 *
 * Pin: ogni casella → UNA installazione (label = mailbox_key, project_key =
 * azienda come COLONNE); config_json porta connection + folders.include=[label] +
 * date_window_days (NON project_key); la password è nel vault; --sync accoda un
 * ConnectorSyncJob; --all crea un'installazione per casella; actor inesistente o
 * credenziali errate falliscono (R14).
 */
final class ConnectorImapInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $touchedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->touchedEnv = [];

        parent::tearDown();
    }

    public function test_installs_one_account_with_columns_and_vaulted_password(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 's3cr3t-app-pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', ['--mailbox' => ['rotta-logistics-1']])
            ->assertExitCode(0);

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', 'default')
            ->where('connector_name', 'imap')
            ->where('label', 'rotta-logistics-1')
            ->firstOrFail();

        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $installation->status);
        // v8.20: project_key + label sono COLONNE.
        $this->assertSame('rotta-logistics', $installation->project_key);
        $this->assertSame('rotta-logistics-1', $installation->label);

        $config = (array) $installation->config_json;
        $this->assertSame('basic', $config['auth_mode']);
        $this->assertSame('rotta.test1.askmydocs@gmail.com', $config['connection']['username']);
        // folders.include = la LABEL della casella; date_window_days presente.
        $this->assertSame(['rotta-logistics-1'], $config['folders']['include']);
        $this->assertIsInt($config['date_window_days']);
        // project_key e password NON stanno in config_json.
        $this->assertArrayNotHasKey('project_key', $config);
        $this->assertArrayNotHasKey('password', $config);
        $this->assertStringNotContainsString('s3cr3t-app-pw', json_encode($config));

        // ...ma è nel vault cifrato.
        $this->assertSame(
            's3cr3t-app-pw',
            app(OAuthCredentialVault::class)->getAccessToken($installation->id),
        );
    }

    public function test_all_creates_one_installation_per_mailbox(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', ['--all' => true])->assertExitCode(0);

        $rows = ConnectorInstallation::query()
            ->where('connector_name', 'imap')
            ->get(['label', 'project_key'])
            ->mapWithKeys(fn ($r) => [$r->label => $r->project_key])
            ->all();

        // Una installazione per casella, project_key (colonna) corretto.
        $this->assertSame([
            'rotta-logistics-1' => 'rotta-logistics',
            'rotta-logistics-2' => 'rotta-logistics',
            'prometeo-antincendio-1' => 'prometeo-antincendio',
            'prometeo-antincendio-2' => 'prometeo-antincendio',
            'passolibero-calzature-1' => 'passolibero-calzature',
            'passolibero-calzature-2' => 'passolibero-calzature',
        ], $rows);
    }

    public function test_reinstall_same_label_is_idempotent(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', ['--mailbox' => ['rotta-logistics-1']])->assertExitCode(0);
        $this->artisan('connector:imap:install', ['--mailbox' => ['rotta-logistics-1']])->assertExitCode(0);

        // Nessun duplicato: la label viene rimossa e ricreata.
        $this->assertSame(
            1,
            ConnectorInstallation::query()->where('connector_name', 'imap')->where('label', 'rotta-logistics-1')->count(),
        );
    }

    public function test_sync_flag_dispatches_a_connector_sync_job(): void
    {
        Queue::fake();
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', [
            '--mailbox' => ['rotta-logistics-1'],
            '--sync' => true,
        ])->assertExitCode(0);

        Queue::assertPushed(ConnectorSyncJob::class);
    }

    public function test_unknown_actor_fails(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', [
            '--mailbox' => ['rotta-logistics-1'],
            '--actor' => 'ghost@nowhere.local',
        ])->assertExitCode(1);

        $this->assertSame(0, ConnectorInstallation::query()->count());
    }

    public function test_bad_credentials_keep_installation_pending_and_fail(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: false);

        $this->artisan('connector:imap:install', ['--mailbox' => ['rotta-logistics-1']])
            ->assertExitCode(1);

        $installation = ConnectorInstallation::query()
            ->where('connector_name', 'imap')
            ->firstOrFail();
        $this->assertSame(ConnectorInstallation::STATUS_PENDING, $installation->status);
        // Login fallito → la password non deve essere vaultata.
        $this->assertNull(app(OAuthCredentialVault::class)->getAccessToken($installation->id));
    }

    private function setPassword(string $envKey, string $value): void
    {
        putenv("{$envKey}={$value}");
        $_ENV[$envKey] = $value;
        $_SERVER[$envKey] = $value;
        $this->touchedEnv[] = $envKey;
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Actor',
            'email' => 'actor-'.uniqid('', true).'@demo.local',
            'password' => bcrypt('secret-password'),
        ]);
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
                return [];
            }

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
        // Il registry costruisce ogni connettore nel costruttore: scarta il
        // singleton così viene ricreato con la factory fake.
        $this->app->forgetInstance(ConnectorRegistry::class);
    }
}
