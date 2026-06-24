<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\ImapMailboxSeeder;
use App\Services\Demo\MailboxSelection;
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
        {--mailbox=* : mailbox_key da popolare (ripetibile), es. rotta-logistics-1}
        {--project=* : project_key: espande a TUTTE le caselle dell\'azienda (ripetibile)}
        {--all : Popola tutte le caselle definite in TestEmailFixtures}
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

        $mailboxKeys = $this->resolveMailboxKeys();
        if ($mailboxKeys === []) {
            $this->error('Nessuna casella selezionata. Usa --all, --mailbox=<key> o --project=<key>.');
            $this->line('Caselle disponibili: '.implode(', ', TestEmailFixtures::mailboxKeys()));

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
                mailboxKeys: $mailboxKeys,
                dryRun: $dryRun,
                purge: $purge,
                retries: (int) $this->option('retries'),
                retryDelaySeconds: (int) $this->option('retry-delay'),
                onMessage: function (string $mailboxKey, int $index, string $subject): void {
                    $this->line(sprintf('  [%s] #%d %s', $mailboxKey, $index + 1, $subject));
                },
            );
        } catch (Throwable $e) {
            // R14/R4 — fallimento rumoroso: credenziali mancanti, casella non
            // raggiungibile, mailbox sconosciuta, append fallito.
            $this->error('Seeding fallito: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($outcomes as $outcome) {
            $verb = $outcome->dryRun ? 'pronte (dry-run)' : 'inviate';
            $purgedNote = $outcome->purged > 0 ? " (purgate {$outcome->purged})" : '';
            $this->info(sprintf(
                '%s [%s → %s, project %s]: %d e-mail %s%s',
                $outcome->companyName,
                $outcome->mailboxKey,
                $outcome->email,
                $outcome->projectKey,
                $outcome->appended,
                $verb,
                $purgedNote,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Risolve le caselle da popolare: --all, --mailbox=<key>, oppure
     * --project=<key> (espanso a tutte le caselle dell'azienda).
     *
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
}
