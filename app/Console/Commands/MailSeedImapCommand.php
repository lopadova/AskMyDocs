<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\ImapMailboxSeeder;
use App\Support\TenantContext;
use Database\Seeders\TestEmailFixtures;
use Illuminate\Console\Command;
use Throwable;

/**
 * Consegna le e-mail di test (APPEND) dentro le caselle IMAP delle aziende,
 * così l'ingest gira poi su messaggi VERI presenti nella mailbox.
 *
 * È la metà "delivery" dell'harness; l'altra metà è `connector:imap:install`
 * (setup connettore) + il sync che fa l'ingest. Vedi
 * docs/testing/email-ingest-e2e.md.
 *
 *   php artisan mail:seed-imap --all --dry-run
 *   php artisan mail:seed-imap --project=rotta-logistics --purge
 */
class MailSeedImapCommand extends Command
{
    protected $signature = 'mail:seed-imap
        {--project=* : project_key da popolare (ripetibile). Vuoto + --all = tutte le aziende di test}
        {--all : Popola tutte le aziende definite in TestEmailFixtures}
        {--purge : Prima elimina i messaggi di test già presenti (header X-AskMyDocs-Seed) — DISTRUTTIVO}
        {--retries=2 : Tentativi extra su errori IMAP transitori (R42)}
        {--retry-delay=60 : Secondi di attesa tra i retry}
        {--dry-run : Costruisce i messaggi senza inviare nulla (non serve la password)}';

    protected $description = 'Inietta via IMAP APPEND le e-mail di test nelle caselle delle aziende (per l\'ingest reale).';

    public function handle(ImapMailboxSeeder $seeder, TenantContext $tenant): int
    {
        // Le installazioni connettore vivono nel tenant 'default' come le aziende
        // di test (R30/R31): allinea il contesto anche se qui non si scrive a DB.
        $tenant->set('default');

        $projectKeys = $this->resolveProjectKeys();
        if ($projectKeys === []) {
            $this->error('Nessuna azienda selezionata. Usa --all oppure --project=<key> (ripetibile).');
            $this->line('Aziende disponibili: '.implode(', ', TestEmailFixtures::projectKeys()));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $purge = (bool) $this->option('purge');

        if ($dryRun) {
            $this->warn('DRY-RUN: nessun messaggio verrà inviato.');
        }
        if ($purge && ! $dryRun) {
            $this->warn('PURGE attivo: i messaggi di test esistenti verranno eliminati prima dell\'APPEND.');
        }

        try {
            $outcomes = $seeder->seed(
                projectKeys: $projectKeys,
                dryRun: $dryRun,
                purge: $purge,
                retries: (int) $this->option('retries'),
                retryDelaySeconds: (int) $this->option('retry-delay'),
                onMessage: function (string $projectKey, int $index, string $subject): void {
                    $this->line(sprintf('  [%s] #%d %s', $projectKey, $index + 1, $subject));
                },
            );
        } catch (Throwable $e) {
            // R14/R4 — fallimento rumoroso: credenziali mancanti, casella non
            // raggiungibile, project_key sconosciuto, append fallito.
            $this->error('Seeding fallito: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($outcomes as $outcome) {
            $verb = $outcome->dryRun ? 'pronte (dry-run)' : 'inviate';
            $purgedNote = $outcome->purged > 0 ? " (purgate {$outcome->purged})" : '';
            $this->info(sprintf(
                '%s [%s → %s]: %d e-mail %s%s',
                $outcome->companyName,
                $outcome->projectKey,
                $outcome->email,
                $outcome->appended,
                $verb,
                $purgedNote,
            ));
        }

        return self::SUCCESS;
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
}
