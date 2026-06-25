<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Services\Demo\MailboxSelection;
use App\Support\TenantContext;
use Database\Seeders\TestEmailFixtures;
use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Throwable;

/**
 * Installa (o re-installa) il connettore IMAP per le caselle di test da CLI,
 * riusando ConfigureConnectorService — così l'intero flusso e-mail→ingest è
 * scriptabile senza la admin UI. Vedi docs/testing/email-ingest-e2e.md.
 *
 * Modello v8.20 MULTI-ACCOUNT: `connector_installations` è unico su
 * (tenant_id, connector_name, **label**), e `label` + `project_key` sono COLONNE
 * (non più config_json). Quindi ogni casella diventa una **installazione a sé**,
 * con `label` = mailbox_key e `project_key` = azienda. Niente più "una sola
 * installazione per tenant": le 6 caselle coesistono, ognuna sincronizza solo la
 * propria label e ingerisce nel proprio project_key. Re-eseguire è idempotente —
 * la riga con quella label viene rimossa e ricreata (le credenziali nel vault
 * cascadano via FK).
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
        {--all : Installa per tutte le caselle di test}
        {--actor= : Email dell\'utente registrato come created_by (default: primo utente)}
        {--sync : Dispatcha un ConnectorSyncJob dopo ogni install}';

    protected $description = 'Installa il connettore IMAP (multi-account) per le caselle di test (riusa ConfigureConnectorService).';

    public function handle(
        ConfigureConnectorService $configurator,
        TenantContext $tenant,
        PackageTenantContext $packageTenant,
    ): int {
        $mailboxKeys = $this->resolveMailboxKeys();
        if ($mailboxKeys === []) {
            $this->error('Nessuna casella selezionata. Usa --all, --mailbox=<key> o --project=<key>.');
            $this->line('Caselle disponibili: '.implode(', ', TestEmailFixtures::mailboxKeys()));

            return self::FAILURE;
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');
        $failed = false;
        $previousTenant = $tenant->current();
        $previousPackageTenant = $packageTenant->current();

        try {
            foreach ($mailboxKeys as $mailboxKey) {
                // Un tenant per azienda (R30/R31): l'installazione nasce nel tenant
                // della casella, così il pannello connettori (scoping per tenant)
                // mostra a ogni azienda SOLO i propri connettori.
                //
                // Vanno settati ENTRAMBI i contesti: ConfigureConnectorService scrive
                // via l'host TenantContext (App\Support), mentre il connettore IMAP, il
                // model ConnectorInstallation e l'OAuthCredentialVault leggono il
                // TenantContext del PACCHETTO (un singleton "snapshot" allineato all'host
                // solo alla prima risoluzione — vedi AppServiceProvider). Senza mirror,
                // configure() creerebbe la riga nel tenant azienda ma il ping/vault la
                // cercherebbe nel tenant precedente → "installation not found".
                $tenantId = TestEmailFixtures::tenantFor($mailboxKey);
                $tenant->set($tenantId);
                $packageTenant->set($tenantId);

                try {
                    $installation = $this->installOne($configurator, $mailboxKey, $tenantId, $actor->id);
                } catch (Throwable $e) {
                    // R14 — credenziali errate / ping IMAP fallito / mailbox ignota.
                    $this->error(sprintf('[%s] install fallita: %s', $mailboxKey, $e->getMessage()));
                    $failed = true;

                    continue;
                }

                if ($sync) {
                    // Multi-account: ogni installazione è indipendente → basta
                    // accodare il job per ciascuna (nel suo tenant).
                    ConnectorSyncJob::dispatch($installation->id, $tenantId);
                    $this->line(sprintf('  → ConnectorSyncJob accodato (installation #%d, tenant %s)', $installation->id, $tenantId));
                }
            }
        } finally {
            $tenant->set($previousTenant);
            $packageTenant->set($previousPackageTenant);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function installOne(
        ConfigureConnectorService $configurator,
        string $mailboxKey,
        string $tenantId,
        int $actorId,
    ): ConnectorInstallation {
        $mailbox = TestEmailFixtures::mailbox($mailboxKey);
        $config = TestEmailFixtures::configJson($mailboxKey);
        $connection = (array) ($config['connection'] ?? []);
        $projectKey = (string) $mailbox['project_key'];

        // Idempotenza re-run: rimuovi l'eventuale installazione con questa label
        // nel tenant dell'azienda (configure() è additivo e la unique
        // (tenant,connector,label) rifiuterebbe il duplicato). La FK su
        // connector_credentials cascada il segreto.
        ConnectorInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('connector_name', self::CONNECTOR)
            ->where('label', $mailboxKey)
            ->delete();

        // Payload: campi del form (connection.* / password→secret / auth_mode) +
        // le COLONNE v8.20 label/project_key.
        $validated = [
            'auth_mode' => 'basic',
            'host' => (string) ($connection['host'] ?? 'imap.gmail.com'),
            'port' => (int) ($connection['port'] ?? 993),
            'encryption' => (string) ($connection['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($connection['validate_cert'] ?? true),
            'username' => (string) $mailbox['email'],
            'password' => TestEmailFixtures::passwordFor($mailboxKey),
            'label' => $mailboxKey,
            'project_key' => $projectKey,
        ];

        $result = $configurator->configure(self::CONNECTOR, $validated, $actorId);
        $installation = $result->installation;

        // configure() persiste i campi del form + le colonne label/project_key.
        // Rimettiamo le chiavi config_json extra che il connettore legge in sync
        // (la label scoping + la finestra temporale).
        $stored = (array) $installation->config_json;
        $stored['folders'] = $config['folders'];
        $stored['date_window_days'] = $config['date_window_days'];
        $installation->config_json = $stored;
        $installation->save();

        $this->info(sprintf(
            '[%s] connettore IMAP su %s → project %s, label %s (installation #%d, status=%s)',
            $mailboxKey,
            $mailbox['email'],
            $projectKey,
            $installation->label,
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
            $this->error('Nessun utente nel DB: esegui prima `php artisan db:seed` (RbacSeeder + CaseStudyUsersSeeder).');
        }

        return $actor;
    }
}
