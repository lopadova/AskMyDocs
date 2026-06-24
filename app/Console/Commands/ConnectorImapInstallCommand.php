<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Services\Demo\MailboxSelection;
use App\Support\TenantContext;
use Database\Seeders\TestEmailFixtures;
use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Throwable;

/**
 * Installa (o riconfigura) il connettore IMAP per le caselle di test da CLI,
 * riusando ConfigureConnectorService — così l'intero flusso e-mail→ingest è
 * scriptabile senza passare dalla admin UI. Vedi docs/testing/email-ingest-e2e.md.
 *
 * Ogni azienda ha 2 caselle (mailbox_key), entrambe mappate sul project_key
 * dell'azienda: installarle entrambe fa confluire le e-mail di tutte e due le
 * inbox nello stesso progetto KB.
 *
 * VINCOLO ARCHITETTURALE — un SOLO connettore IMAP per tenant:
 * `connector_installations` ha UNIQUE (tenant_id, connector_name), quindi nel
 * tenant 'default' esiste una sola riga `imap`. Per ingerire più caselle nello
 * stesso tenant si riusa quella riga UNA casella alla volta:
 *   - ad ogni (ri)configurazione il cursore di sync viene AZZERATO
 *     (last_sync_at=null + mailboxes_state svuotato) così il sync successivo è un
 *     FULL clean della casella corrente (le e-mail, datate nel passato, non
 *     verrebbero altrimenti riprese da un sync incrementale);
 *   - i documenti già ingeriti dalle caselle precedenti restano (l'ingest è
 *     additivo per project_key); reconcile_deletions è OFF di default, quindi
 *     riconfigurare non cancella nulla.
 * Con più caselle il sync DEVE essere sincrono e serializzato (configura A →
 * sync A → configura B → ...), altrimenti job in coda leggerebbero tutti
 * l'ultima configurazione: per questo `--all`/multi richiede `--sync`.
 *
 * ConfigureConnectorService verifica DAVVERO le credenziali (ping IMAP) prima di
 * portare l'installazione ad ACTIVE; per i test offline usare
 * CONNECTOR_IMAP_FAKE_PING=true (factory IMAP fake).
 *
 *   php artisan connector:imap:install --all --sync
 *   php artisan connector:imap:install --project=rotta-logistics --sync   # 2 caselle
 *   php artisan connector:imap:install --mailbox=rotta-logistics-1 --sync
 */
class ConnectorImapInstallCommand extends Command
{
    private const CONNECTOR = 'imap';

    protected $signature = 'connector:imap:install
        {--mailbox=* : mailbox_key da installare (ripetibile), es. rotta-logistics-1}
        {--project=* : project_key: espande a TUTTE le caselle dell\'azienda (ripetibile)}
        {--all : Installa per tutte le caselle di test (richiede --sync)}
        {--actor= : Email dell\'utente registrato come created_by alla PRIMA creazione della riga (default: primo utente)}
        {--sync : Sincronizza dopo l\'install (sincrono+serializzato se più caselle)}';

    protected $description = 'Installa il connettore IMAP per le caselle di test (riusa ConfigureConnectorService).';

    public function handle(
        ConfigureConnectorService $configurator,
        OAuthCredentialVault $vault,
        TenantContext $tenant,
    ): int {
        // Le installazioni connettore sono tenant-aware (R30/R31): le aziende di
        // test vivono nel tenant 'default'.
        $tenant->set('default');

        $mailboxKeys = $this->resolveMailboxKeys();
        if ($mailboxKeys === []) {
            $this->error('Nessuna casella selezionata. Usa --all, --mailbox=<key> o --project=<key>.');
            $this->line('Caselle disponibili: '.implode(', ', TestEmailFixtures::mailboxKeys()));

            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');
        $multi = count($mailboxKeys) > 1;

        if ($multi && ! $sync) {
            // Vincolo single-installation: senza sync serializzato sopravviverebbe
            // solo l'ultima configurazione (R14 — fallisci, non fingere successo).
            $this->error(
                'Più caselle selezionate ma il connettore IMAP ammette UNA sola installazione per tenant '
                .'(UNIQUE tenant_id+connector_name). Usa --sync: ogni casella viene configurata e '
                .'sincronizzata (sincrono, serializzato) prima della successiva. In alternativa installa '
                .'una casella alla volta.',
            );

            return self::FAILURE;
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($mailboxKeys as $mailboxKey) {
            try {
                $installation = $this->installOne($configurator, $vault, $mailboxKey, $actor->id);
            } catch (Throwable $e) {
                // R14 — credenziali errate / ping IMAP fallito / mailbox ignota.
                $this->error(sprintf('[%s] install fallita: %s', $mailboxKey, $e->getMessage()));
                $failed = true;

                continue;
            }

            if (! $sync) {
                continue;
            }

            // Multi → sincrono+serializzato: il sync deve leggere la config di
            // QUESTA casella prima che la prossima riconfiguri la riga unica.
            // Singola casella → coda (parità con l'admin "sync now").
            if ($multi) {
                ConnectorSyncJob::dispatchSync($installation->id, 'default');
                $this->line(sprintf('  → sync (sincrono) eseguito per %s', $mailboxKey));
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
        string $mailboxKey,
        int $actorId,
    ): ConnectorInstallation {
        $mailbox = TestEmailFixtures::mailbox($mailboxKey);
        $config = TestEmailFixtures::configJson($mailboxKey);
        $connection = (array) ($config['connection'] ?? []);
        $projectKey = (string) $mailbox['project_key'];

        // Payload keyed by SCHEMA field name (ConfigureConnectorService::splitPayload
        // li smista per target: connection.* / secret / auth_mode / project_key).
        $validated = [
            'auth_mode' => 'basic',
            'host' => (string) ($connection['host'] ?? 'imap.gmail.com'),
            'port' => (int) ($connection['port'] ?? 993),
            'encryption' => (string) ($connection['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($connection['validate_cert'] ?? true),
            'username' => (string) $mailbox['email'],
            'password' => TestEmailFixtures::passwordFor($mailboxKey),
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

        // Azzera il cursore: la riga unica viene riusata tra caselle diverse, e un
        // sync incrementale (since=last_sync_at) salterebbe le e-mail datate nel
        // passato. Con last_sync_at=null il prossimo sync è FULL clean.
        $installation->last_sync_at = null;
        $installation->save();
        $vault->setExtraKey($installation->id, 'mailboxes_state', []);

        $this->info(sprintf(
            '[%s] connettore IMAP configurato su %s → project %s (installation #%d, status=%s)',
            $mailboxKey,
            $mailbox['email'],
            $projectKey,
            $installation->id,
            $installation->status,
        ));

        return $installation;
    }

    /**
     * @return list<string>
     */
    private function resolveMailboxKeys(): array
    {
        return MailboxSelection::resolve(
            all: (bool) $this->option('all'),
            mailboxes: (array) $this->option('mailbox'),
            projects: (array) $this->option('project'),
        );
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
