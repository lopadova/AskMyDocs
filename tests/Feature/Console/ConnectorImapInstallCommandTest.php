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
 * Feature test di `connector:imap:install`. L'IMAP è il solo confine esterno:
 * si binda un fake {@see ImapClientFactoryInterface} (ping configurabile) come in
 * ConfigureConnectorTest, così il flusso configure() gira offline.
 *
 * Pin: l'install crea la riga ACTIVE con config_json corretto (connection +
 * project_key + folders.include=[INBOX] + date_window_days) e vaulta la password
 * (mai in config_json); --sync accoda un ConnectorSyncJob; actor inesistente
 * fallisce (R14).
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

    public function test_installs_activates_vaults_password_and_merges_extra_config(): void
    {
        $this->setPassword('CONNECTOR_TEST_ROTTA_PASSWORD', 's3cr3t-app-pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', ['--project' => ['rotta-logistics']])
            ->assertExitCode(0);

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', 'default')
            ->where('connector_name', 'imap')
            ->firstOrFail();

        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $installation->status);

        $config = (array) $installation->config_json;
        $this->assertSame('basic', $config['auth_mode']);
        $this->assertSame('rotta.test.askmydocs@gmail.com', $config['connection']['username']);
        $this->assertSame('rotta-logistics', $config['project_key']);
        // Le chiavi extra che configure() non persiste sono ri-aggiunte.
        $this->assertSame(['INBOX'], $config['folders']['include']);
        $this->assertIsInt($config['date_window_days']);
        // La password non finisce mai in config_json.
        $this->assertArrayNotHasKey('password', $config);
        $this->assertStringNotContainsString('s3cr3t-app-pw', json_encode($config));

        // ...ma è nel vault cifrato.
        $this->assertSame(
            's3cr3t-app-pw',
            app(OAuthCredentialVault::class)->getAccessToken($installation->id),
        );
    }

    public function test_sync_flag_dispatches_a_connector_sync_job(): void
    {
        Queue::fake();
        $this->setPassword('CONNECTOR_TEST_ROTTA_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', [
            '--project' => ['rotta-logistics'],
            '--sync' => true,
        ])->assertExitCode(0);

        Queue::assertPushed(ConnectorSyncJob::class);
    }

    public function test_multi_company_without_sync_is_rejected(): void
    {
        // Vincolo single-installation per tenant: senza --sync fallisce loud
        // invece di installare solo l'ultima azienda (Finding #1 / R14).
        $this->setPassword('CONNECTOR_TEST_ROTTA_PASSWORD', 'pw');
        $this->setPassword('CONNECTOR_TEST_PROMETEO_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', ['--all' => true])
            ->assertExitCode(1);

        $this->assertSame(0, ConnectorInstallation::query()->count());
    }

    public function test_all_with_sync_configures_each_company_serially(): void
    {
        // La riga (tenant,imap) è unica: il sync DEVE girare con la config di
        // ogni azienda PRIMA che la successiva la sovrascriva. Il recorder cattura
        // il project_key SOLO al momento del sync (in listMailboxes(), che il path
        // di configure/ping NON chiama): se la serializzazione è corretta vede
        // [A, B, C] nell'ordine; se il comando accodasse/clobberasse, vedrebbe
        // tre volte l'ULTIMA azienda → il test fallirebbe (R16).
        $this->setPassword('CONNECTOR_TEST_ROTTA_PASSWORD', 'pw-r');
        $this->setPassword('CONNECTOR_TEST_PROMETEO_PASSWORD', 'pw-p');
        $this->setPassword('CONNECTOR_TEST_PASSOLIBERO_PASSWORD', 'pw-l');
        $this->makeUser();

        $recorder = new class
        {
            /** @var list<string> */
            public array $projectKeys = [];
        };
        $this->bindRecordingImapFactory($recorder);

        $this->artisan('connector:imap:install', ['--all' => true, '--sync' => true])
            ->assertExitCode(0);

        // Ogni sync ha visto la config della SUA azienda, nell'ordine serializzato.
        $this->assertSame(
            ['rotta-logistics', 'prometeo-antincendio', 'passolibero-calzature'],
            $recorder->projectKeys,
        );

        // Resta una sola riga (documenta il vincolo single-installation).
        $this->assertSame(1, ConnectorInstallation::query()->where('connector_name', 'imap')->count());
    }

    public function test_unknown_actor_fails(): void
    {
        $this->setPassword('CONNECTOR_TEST_ROTTA_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connector:imap:install', [
            '--project' => ['rotta-logistics'],
            '--actor' => 'ghost@nowhere.local',
        ])->assertExitCode(1);

        $this->assertSame(0, ConnectorInstallation::query()->count());
    }

    public function test_bad_credentials_keep_installation_pending_and_fail(): void
    {
        $this->setPassword('CONNECTOR_TEST_ROTTA_PASSWORD', 'pw');
        $this->makeUser();
        $this->bindImapFactory(pingSucceeds: false);

        $this->artisan('connector:imap:install', ['--project' => ['rotta-logistics']])
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
        // Il registry costruisce ogni connettore nel costruttore: scarta il
        // singleton così viene ricreato con la factory fake.
        $this->app->forgetInstance(ConnectorRegistry::class);
    }

    /**
     * Factory il cui client registra, in `listMailboxes()` (chiamato SOLO durante
     * il sync, mai durante configure/ping), il project_key correntemente salvato
     * sulla riga unica (tenant,imap). Mailbox vuota → il sync è un no-op clean.
     * `$recorder->projectKeys` raccoglie così l'azienda attiva ad ogni sync.
     */
    private function bindRecordingImapFactory(object $recorder): void
    {
        $client = new class($recorder) implements ImapClientInterface
        {
            public function __construct(private readonly object $recorder) {}

            public function ping(): bool
            {
                return true;
            }

            public function close(): void {}

            public function listMailboxes(): array
            {
                $installation = ConnectorInstallation::query()
                    ->where('tenant_id', 'default')
                    ->where('connector_name', 'imap')
                    ->first();

                $this->recorder->projectKeys[] = (string) (((array) $installation?->config_json)['project_key'] ?? '');

                return []; // nessuna cartella → sync no-op (niente fetch reale)
            }

            public function selectMailbox(string $name): MailboxState
            {
                throw new \LogicException('not reached (no mailboxes)');
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                throw new \LogicException('not reached (no mailboxes)');
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                throw new \LogicException('not reached (no mailboxes)');
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
