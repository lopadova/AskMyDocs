<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Support\TenantContext;
use Database\Seeders\TestEmailFixtures;
use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Throwable;

/**
 * Installa (o riconfigura) il connettore IMAP per le aziende di test da CLI,
 * riusando ConfigureConnectorService — così l'intero flusso e-mail→ingest è
 * scriptabile senza passare dalla admin UI. Vedi docs/testing/email-ingest-e2e.md.
 *
 * VINCOLO ARCHITETTURALE — un SOLO connettore IMAP per tenant:
 * `connector_installations` ha UNIQUE (tenant_id, connector_name), quindi nel
 * tenant 'default' esiste una sola riga `imap`. Per testare più aziende nello
 * stesso tenant si riusa quella riga UNA azienda alla volta:
 *   - ad ogni (ri)configurazione il cursore di sync viene AZZERATO
 *     (last_sync_at=null + mailboxes_state svuotato) così il sync successivo è un
 *     FULL clean della casella corrente (le e-mail, datate nel passato, non
 *     verrebbero altrimenti riprese da un sync incrementale);
 *   - i documenti già ingeriti dalle aziende precedenti restano (l'ingest è
 *     additivo per project_key); reconcile_deletions è OFF di default, quindi
 *     riconfigurare non cancella nulla.
 * Con più aziende il sync DEVE essere sincrono e serializzato (configura A →
 * sync A → configura B → ...), altrimenti job in coda leggerebbero tutti
 * l'ultima configurazione: per questo `--all`/multi richiede `--sync`.
 *
 * ConfigureConnectorService verifica DAVVERO le credenziali (ping IMAP) prima di
 * portare l'installazione ad ACTIVE; per i test offline usare
 * CONNECTOR_IMAP_FAKE_PING=true (factory IMAP fake).
 *
 *   php artisan connector:imap:install --all --sync
 *   php artisan connector:imap:install --project=rotta-logistics --sync
 */
class ConnectorImapInstallCommand extends Command
{
    private const CONNECTOR = 'imap';

    protected $signature = 'connector:imap:install
        {--project=* : project_key da installare (ripetibile). Vuoto + --all = tutte}
        {--all : Installa per tutte le aziende di test (richiede --sync)}
        {--actor= : Email dell\'utente registrato come created_by alla PRIMA creazione della riga (default: primo utente)}
        {--sync : Sincronizza dopo l\'install (sincrono+serializzato se più aziende)}';

    protected $description = 'Installa il connettore IMAP per le aziende di test (riusa ConfigureConnectorService).';

    public function handle(
        ConfigureConnectorService $configurator,
        OAuthCredentialVault $vault,
        TenantContext $tenant,
    ): int {
        // Le installazioni connettore sono tenant-aware (R30/R31): le aziende di
        // test vivono nel tenant 'default'.
        $tenant->set('default');

        $projectKeys = $this->resolveProjectKeys();
        if ($projectKeys === []) {
            $this->error('Nessuna azienda selezionata. Usa --all oppure --project=<key>.');
            $this->line('Aziende disponibili: '.implode(', ', TestEmailFixtures::projectKeys()));

            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');
        $multi = count($projectKeys) > 1;

        if ($multi && ! $sync) {
            // Vincolo single-installation: senza sync serializzato sopravviverebbe
            // solo l'ultima configurazione (R14 — fallisci, non fingere successo).
            $this->error(
                'Più aziende selezionate ma il connettore IMAP ammette UNA sola installazione per tenant '
                .'(UNIQUE tenant_id+connector_name). Usa --sync: ogni azienda viene configurata e '
                .'sincronizzata (sincrono, serializzato) prima della successiva. In alternativa installa '
                .'una azienda alla volta.',
            );

            return self::FAILURE;
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($projectKeys as $projectKey) {
            try {
                $installation = $this->installOne($configurator, $vault, $projectKey, $actor->id);
            } catch (Throwable $e) {
                // R14 — credenziali errate / ping IMAP fallito / project_key ignoto.
                $this->error(sprintf('[%s] install fallita: %s', $projectKey, $e->getMessage()));
                $failed = true;

                continue;
            }

            if (! $sync) {
                continue;
            }

            // Multi → sincrono+serializzato: il sync deve leggere la config di
            // QUESTA azienda prima che la prossima riconfiguri la riga unica.
            // Singola azienda → coda (parità con l'admin "sync now").
            if ($multi) {
                ConnectorSyncJob::dispatchSync($installation->id, 'default');
                $this->line(sprintf('  → sync (sincrono) eseguito per %s', $projectKey));
            } else {
                ConnectorSyncJob::dispatch($installation->id, 'default');
                $this->line(sprintf('  → ConnectorSyncJob accodato (installation #%d)', $installation->id));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function installOne(
        ConfigureConnectorService $configurator,
        OAuthCredentialVault $vault,
        string $projectKey,
        int $actorId,
    ): ConnectorInstallation {
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

        // Azzera il cursore: la riga unica viene riusata tra aziende diverse, e un
        // sync incrementale (since=last_sync_at) salterebbe le e-mail datate nel
        // passato. Con last_sync_at=null il prossimo sync è FULL clean.
        $installation->last_sync_at = null;
        $installation->save();
        $vault->setExtraKey($installation->id, 'mailboxes_state', []);

        $this->info(sprintf(
            '[%s] connettore IMAP configurato su %s (installation #%d, status=%s)',
            $projectKey,
            $account['email'],
            $installation->id,
            $installation->status,
        ));

        return $installation;
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
