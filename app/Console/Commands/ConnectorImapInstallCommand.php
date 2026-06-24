<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Support\TenantContext;
use Database\Seeders\TestEmailFixtures;
use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Throwable;

/**
 * Installa (o riconfigura) il connettore IMAP per ciascuna azienda di test da
 * CLI, riusando ConfigureConnectorService — così l'intero flusso e-mail→ingest
 * è scriptabile senza passare dalla admin UI. Vedi docs/testing/email-ingest-e2e.md.
 *
 * ConfigureConnectorService verifica DAVVERO le credenziali (ping IMAP) prima di
 * portare l'installazione ad ACTIVE; per i test offline usare
 * CONNECTOR_IMAP_FAKE_PING=true (factory IMAP fake).
 *
 *   php artisan connector:imap:install --all --sync
 *   php artisan connector:imap:install --project=rotta-logistics --actor=super@demo.local
 */
class ConnectorImapInstallCommand extends Command
{
    private const CONNECTOR = 'imap';

    protected $signature = 'connector:imap:install
        {--project=* : project_key da installare (ripetibile). Vuoto + --all = tutte}
        {--all : Installa per tutte le aziende di test}
        {--actor= : Email dell\'utente registrato come created_by (default: primo utente)}
        {--sync : Dispatcha subito un ConnectorSyncJob dopo l\'install}';

    protected $description = 'Installa il connettore IMAP per le aziende di test (riusa ConfigureConnectorService).';

    public function handle(ConfigureConnectorService $configurator, TenantContext $tenant): int
    {
        // Le installazioni connettore sono tenant-aware (R30/R31): le aziende di
        // test vivono nel tenant 'default'.
        $tenant->set('default');

        $projectKeys = $this->resolveProjectKeys();
        if ($projectKeys === []) {
            $this->error('Nessuna azienda selezionata. Usa --all oppure --project=<key>.');
            $this->line('Aziende disponibili: '.implode(', ', TestEmailFixtures::projectKeys()));

            return self::FAILURE;
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');
        $failed = false;

        foreach ($projectKeys as $projectKey) {
            try {
                $this->installOne($configurator, $projectKey, $actor->id, $sync);
            } catch (Throwable $e) {
                // R14 — credenziali errate / ping IMAP fallito / project_key ignoto.
                $this->error(sprintf('[%s] install fallita: %s', $projectKey, $e->getMessage()));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function installOne(
        ConfigureConnectorService $configurator,
        string $projectKey,
        int $actorId,
        bool $sync,
    ): void {
        $account = TestEmailFixtures::account($projectKey);
        $config = TestEmailFixtures::configJson($projectKey);
        $connection = (array) ($config['connection'] ?? []);

        // Payload keyed by SCHEMA field name (ConfigureConnectorService::splitPayload
        // li smista per target: connection.* / secret / auth_mode / project_key).
        $validated = [
            'auth_mode' => 'basic',
            'host' => (string) ($connection['host'] ?? 'imap.gmail.com'),
            'port' => (int) ($connection['port'] ?? 993),
            'encryption' => (string) ($connection['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($connection['validate_cert'] ?? true),
            'username' => (string) $account['email'],
            'password' => TestEmailFixtures::passwordFor($projectKey),
            'project_key' => $projectKey,
        ];

        $result = $configurator->configure(self::CONNECTOR, $validated, $actorId);
        $installation = $result->installation;

        // configure() persiste solo i campi del form + project_key: rimettiamo le
        // chiavi extra (folders/date_window_days) che il connettore legge in sync.
        $stored = (array) $installation->config_json;
        $stored['folders'] = $config['folders'];
        $stored['date_window_days'] = $config['date_window_days'];
        $installation->config_json = $stored;
        $installation->save();

        $this->info(sprintf(
            '[%s] connettore IMAP %s su %s (installation #%d, status=%s)',
            $projectKey,
            'configurato',
            $account['email'],
            $installation->id,
            $installation->status,
        ));

        if ($sync) {
            ConnectorSyncJob::dispatch($installation->id, 'default');
            $this->line(sprintf('  → ConnectorSyncJob accodato (installation #%d)', $installation->id));
        }
    }

    /**
     * @return list<string>
     */
    private function resolveProjectKeys(): array
    {
        if ((bool) $this->option('all')) {
            return TestEmailFixtures::projectKeys();
        }

        /** @var list<string> $selected */
        $selected = array_values(array_filter(array_map(
            'trim',
            (array) $this->option('project'),
        )));

        return $selected;
    }

    private function resolveActor(): ?User
    {
        $email = $this->option('actor');

        if (is_string($email) && $email !== '') {
            $actor = User::where('email', $email)->first();
            if ($actor === null) {
                $this->error("Utente '{$email}' non trovato (--actor).");
            }

            return $actor;
        }

        $actor = User::query()->orderBy('id')->first();
        if ($actor === null) {
            $this->error('Nessun utente nel DB: esegui prima `php artisan db:seed` (RbacSeeder + DemoSeeder/CaseStudyUsersSeeder).');
        }

        return $actor;
    }
}
