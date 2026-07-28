<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\EmailDataset\DatasetVersion;
use App\Services\Demo\EmailDataset\EmailDatasetReader;
use App\Services\Demo\EmailDatasetConfirmationException;
use App\Services\Demo\EmailDatasetEnvironmentGuard;
use App\Services\Demo\EmailDatasetOperationAudit;
use App\Services\Demo\EmailDatasetOperationConfirmation;
use App\Services\Demo\EmailDatasetOperationContext;
use App\Services\Demo\EmailDatasetSeedRequest;
use App\Services\Demo\ImapMailboxSeeder;
use App\Services\Demo\MailboxSelection;
use App\Services\Demo\SeedOutcome;
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
        {--dataset-version= : Versione generata da demo:generate-case-study-emails}
        {--profile= : Risolve la versione standard del profilo gold, demo, large o stress}
        {--dataset-root=storage/app/demo-email-datasets : Directory radice dei dataset generati}
        {--batch-size=100 : Intervallo di checkpoint per il resume}
        {--resume : Riprende dal checkpoint verificato della mailbox}
        {--summary-only : Nasconde i subject per messaggio; mantiene i riepiloghi periodici}
        {--progress-every=100 : Frequenza delle righe di avanzamento}
        {--estimate-cost : Preflight completo senza rete, con conteggi di ingest}
        {--purge-dataset : Elimina prima solo la dataset version selezionata}
        {--purge-only : Esegue soltanto --purge-dataset, senza riappendere}
        {--purge-all-seeded : Elimina prima tutte le fixture della mailbox — DISTRUTTIVO}
        {--purge : Alias legacy di --purge-all-seeded — DISTRUTTIVO}
        {--preview-purge : Emette un confirm token monouso senza toccare la rete}
        {--confirm-token= : Token DB-backed emesso da --preview-purge}
        {--actor= : Identità operatore legata al token e all’audit}
        {--dry-run : Costruisce e valida i messaggi senza inviare nulla (non serve la password)}';

    protected $description = 'Inietta via IMAP APPEND le e-mail di test nelle caselle delle aziende (per l\'ingest reale).';

    public function handle(
        ImapMailboxSeeder $seeder,
        EmailDatasetReader $datasetReader,
        EmailDatasetEnvironmentGuard $environmentGuard,
        EmailDatasetOperationConfirmation $confirmation,
        EmailDatasetOperationAudit $audit,
    ): int {
        $mailboxKeys = $this->resolveMailboxKeys();
        if ($mailboxKeys === []) {
            $this->error('Nessuna casella selezionata. Usa --all, --mailbox=<key> o --project=<key>.');
            $this->line('Caselle disponibili: '.implode(', ', TestEmailFixtures::mailboxKeys()));

            return self::FAILURE;
        }

        $datasetVersion = trim((string) $this->option('dataset-version'));
        $profile = trim((string) $this->option('profile'));
        $usesDataset = $datasetVersion !== '' || $profile !== '';
        $dryRun = (bool) $this->option('dry-run') || (bool) $this->option('estimate-cost');
        $purgeAll = (bool) $this->option('purge') || (bool) $this->option('purge-all-seeded');
        $purgeDataset = (bool) $this->option('purge-dataset');
        $purgeOnly = (bool) $this->option('purge-only');
        $previewPurge = (bool) $this->option('preview-purge');
        $destructive = $purgeAll || $purgeDataset;
        $actor = trim((string) $this->option('actor'));
        if ($actor === '') {
            $actor = 'cli:'.(get_current_user() ?: 'unknown');
        }
        if (mb_strlen($actor) > 120) {
            $this->error('--actor non può superare 120 caratteri.');

            return self::INVALID;
        }

        $batchSize = $this->positiveIntegerOption('batch-size');
        $progressEvery = $this->positiveIntegerOption('progress-every');
        if ($batchSize === null || $progressEvery === null) {
            return self::INVALID;
        }

        if (! $usesDataset && (
            (bool) $this->option('resume')
            || $purgeDataset
            || (bool) $this->option('estimate-cost')
        )) {
            $this->error('--resume, --purge-dataset e --estimate-cost richiedono --profile o --dataset-version.');

            return self::INVALID;
        }
        if ($purgeDataset && $purgeAll) {
            $this->error('--purge-dataset e --purge-all-seeded/--purge sono mutuamente esclusivi.');

            return self::INVALID;
        }
        if ($purgeOnly && ! $purgeDataset) {
            $this->error('--purge-only richiede --purge-dataset.');

            return self::INVALID;
        }
        if ($purgeOnly && ($dryRun || (bool) $this->option('resume'))) {
            $this->error('--purge-only non può essere combinato con dry-run, estimate-cost o resume.');

            return self::INVALID;
        }
        if ($previewPurge && (! $destructive || $dryRun)) {
            $this->error('--preview-purge richiede un purge esplicito e non si combina con dry-run/estimate-cost.');

            return self::INVALID;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN: nessun messaggio verrà inviato.');
        }
        if ($purgeAll && ! $dryRun) {
            $this->warn('PURGE AMPIO: tutte le fixture della mailbox verranno eliminate prima dell\'APPEND.');
        }
        if ($purgeDataset && ! $dryRun) {
            $this->warn('PURGE DATASET: verrà eliminata solo la dataset version selezionata.');
        }

        $operationContext = null;
        $auditHandles = [];
        try {
            $datasetDirectory = null;
            $manifest = null;
            if ($usesDataset) {
                $datasetVersion = $this->resolveDatasetVersion($datasetVersion, $profile);
                $datasetRoot = $this->resolveDatasetRoot();
                $datasetDirectory = $datasetReader->datasetDirectory($datasetRoot, $datasetVersion);
                $manifest = $datasetReader->manifestForVersion($datasetRoot, $datasetVersion);

                if ($profile !== '' && ($manifest['profile'] ?? null) !== $profile) {
                    throw new \RuntimeException(
                        "Il manifest {$datasetVersion} appartiene al profilo "
                        .(string) ($manifest['profile'] ?? 'sconosciuto').", non {$profile}.",
                    );
                }
            }

            $operationContext = $this->operationContext(
                mailboxKeys: $mailboxKeys,
                actor: $actor,
                usesDataset: $usesDataset,
                datasetVersion: $datasetVersion,
                datasetDirectory: $datasetDirectory,
                purgeDataset: $purgeDataset,
                purgeAll: $purgeAll,
                purgeOnly: $purgeOnly,
                dryRun: $dryRun,
                batchSize: $batchSize,
            );

            if ($previewPurge) {
                $issued = $confirmation->issue($operationContext);
                $this->info('Confirm token: '.$issued['token']);
                $this->line('Scade: '.$issued['expires_at']);
                $this->warn('Il token è monouso e valido soltanto per questa selezione e questi argomenti.');

                return self::SUCCESS;
            }

            if (! $dryRun) {
                $environmentGuard->assertRemoteMutationAllowed();
                $seeder->assertRemotePreflight($mailboxKeys, $datasetDirectory);
                if ($destructive) {
                    $confirmation->consume(
                        $operationContext,
                        trim((string) $this->option('confirm-token')),
                    );
                }
                $auditHandles = $audit->begin($operationContext);
            }
            $onOutcome = $auditHandles === []
                ? null
                : function (SeedOutcome $outcome) use (&$auditHandles, $audit): void {
                    $handle = $auditHandles[$outcome->mailboxKey] ?? null;
                    if ($handle === null) {
                        throw new \RuntimeException(
                            "Audit handle mancante per {$outcome->mailboxKey}.",
                        );
                    }
                    $audit->complete(
                        [$outcome->mailboxKey => $handle],
                        [$outcome->mailboxKey => [
                            'appended' => $outcome->appended,
                            'purged' => $outcome->purged,
                        ]],
                    );
                    unset($auditHandles[$outcome->mailboxKey]);
                };

            $summaryOnly = (bool) $this->option('summary-only');
            $onMessage = $summaryOnly
                ? null
                : function (string $mailboxKey, int $index, string $subject) use ($progressEvery): void {
                    $number = $index + 1;
                    if ($this->output->isVerbose() || $number % $progressEvery === 0) {
                        $this->line(sprintf('  [%s] #%d %s', $mailboxKey, $number, $subject));
                    }
                };
            $lastReported = [];
            $appendStartedAt = [];
            $appendStartedFrom = [];
            $onProgress = function (
                string $mailboxKey,
                string $phase,
                int $current,
                ?int $total,
            ) use (
                &$appendStartedAt,
                &$appendStartedFrom,
                &$lastReported,
                $progressEvery,
            ): void {
                if ($phase === ImapMailboxSeeder::PROGRESS_WAITING_LOCK) {
                    $this->line(sprintf(
                        '  [%s] attesa lock IMAP; %d e-mail previste.',
                        $mailboxKey,
                        $total ?? 0,
                    ));

                    return;
                }
                if ($phase === ImapMailboxSeeder::PROGRESS_LOCK_ACQUIRED) {
                    $this->line("  [{$mailboxKey}] lock IMAP acquisito.");

                    return;
                }
                if ($phase === ImapMailboxSeeder::PROGRESS_PURGE_RECOVERY_STARTED) {
                    $lastReported["{$mailboxKey}:purge"] = 0;
                    $this->line("  [{$mailboxKey}] recovery del purge interrotto in corso...");

                    return;
                }
                if ($phase === ImapMailboxSeeder::PROGRESS_PURGE_STARTED) {
                    $lastReported["{$mailboxKey}:purge"] = 0;
                    $this->line("  [{$mailboxKey}] purge selettivo in corso...");

                    return;
                }
                if ($phase === ImapMailboxSeeder::PROGRESS_PURGE_DELETED) {
                    $key = "{$mailboxKey}:purge";
                    $previous = $lastReported[$key] ?? 0;
                    if (
                        ! $this->output->isVerbose()
                        && $current - $previous < $progressEvery
                    ) {
                        return;
                    }

                    $lastReported[$key] = $current;
                    $this->line("  [{$mailboxKey}] purge: {$current} e-mail eliminate.");

                    return;
                }
                if ($phase === ImapMailboxSeeder::PROGRESS_PURGE_COMPLETED) {
                    $this->line("  [{$mailboxKey}] purge completato: {$current} e-mail eliminate.");

                    return;
                }
                if ($phase === ImapMailboxSeeder::PROGRESS_APPEND_STARTED) {
                    $appendStartedAt[$mailboxKey] = microtime(true);
                    $appendStartedFrom[$mailboxKey] = $current;
                    $lastReported["{$mailboxKey}:append"] = $current;
                    $this->line(sprintf(
                        '  [%s] APPEND avviato: %d/%d già confermate.',
                        $mailboxKey,
                        $current,
                        $total ?? 0,
                    ));

                    return;
                }
                if ($phase !== ImapMailboxSeeder::PROGRESS_APPEND_STORED) {
                    return;
                }

                $key = "{$mailboxKey}:append";
                $previous = $lastReported[$key] ?? ($appendStartedFrom[$mailboxKey] ?? 0);
                if ($current !== $total && $current - $previous < $progressEvery) {
                    return;
                }

                $lastReported[$key] = $current;
                $startedAt = $appendStartedAt[$mailboxKey] ?? microtime(true);
                $startedFrom = $appendStartedFrom[$mailboxKey] ?? 0;
                $elapsed = max(0.001, microtime(true) - $startedAt);
                $confirmed = max(0, $current - $startedFrom);
                $rate = $confirmed / $elapsed;
                $eta = $rate > 0.0 && $total !== null
                    ? max(0, (int) ceil(($total - $current) / $rate))
                    : null;
                $etaNote = $eta !== null ? ', ETA '.$this->formatDuration($eta) : '';

                $this->line(sprintf(
                    '  [%s] APPEND confermati: %d/%d (%.2f e-mail/s%s).',
                    $mailboxKey,
                    $current,
                    $total ?? 0,
                    $rate,
                    $etaNote,
                ));
            };

            if ($usesDataset) {
                $this->warnIfRemoteStress(
                    $mailboxKeys,
                    (array) $manifest,
                    $dryRun,
                    $purgeOnly,
                );

                $outcomes = $seeder->seedDataset(
                    new EmailDatasetSeedRequest(
                        mailboxKeys: $mailboxKeys,
                        datasetDirectory: (string) $datasetDirectory,
                        dryRun: $dryRun,
                        resume: (bool) $this->option('resume'),
                        purgeDataset: $purgeDataset && ! $dryRun,
                        purgeAllSeeded: $purgeAll && ! $dryRun,
                        purgeOnly: $purgeOnly,
                        checkpointEvery: $batchSize,
                    ),
                    $onMessage,
                    $onProgress,
                    $onOutcome,
                );
            } else {
                $outcomes = $seeder->seed(
                    mailboxKeys: $mailboxKeys,
                    dryRun: $dryRun,
                    purge: $purgeAll,
                    onMessage: $onMessage,
                    onProgress: $onProgress,
                    onOutcome: $onOutcome,
                );
            }
        } catch (Throwable $e) {
            if ($auditHandles !== []) {
                try {
                    $audit->fail($auditHandles, $e);
                } catch (Throwable $auditException) {
                    $this->error('Audit fallito: '.$auditException->getMessage());
                }
            } elseif ($operationContext !== null && ! $dryRun) {
                try {
                    $reason = $e instanceof EmailDatasetConfirmationException
                        ? 'confirmation_'.$e->reason
                        : 'environment_or_preflight_rejected';
                    $audit->reject($operationContext, $reason);
                } catch (Throwable $auditException) {
                    $this->error('Audit del rifiuto fallito: '.$auditException->getMessage());
                }
            }

            // R14/R4 — fallimento rumoroso: credenziali mancanti, casella non
            // raggiungibile, mailbox sconosciuta, append fallito.
            $this->error('Seeding fallito: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($outcomes as $outcome) {
            $verb = $outcome->dryRun
                ? 'validate (dry-run)'
                : ($purgeOnly ? 'rimosse (purge-only)' : 'inviate');
            $reported = $purgeOnly ? $outcome->purged : $outcome->appended;
            $purgedNote = ! $purgeOnly && $outcome->purged > 0
                ? " (purgate {$outcome->purged})"
                : '';
            $resumeNote = $outcome->resumed > 0 ? ", riprese {$outcome->resumed}" : '';
            $presentNote = $outcome->alreadyPresent > 0
                ? ", già presenti {$outcome->alreadyPresent}"
                : '';
            $expectedNote = ! $purgeOnly && $outcome->expected > 0
                ? "/{$outcome->expected}"
                : '';
            $datasetNote = $outcome->datasetVersion !== null
                ? ", dataset {$outcome->datasetVersion}"
                : '';
            $this->info(sprintf(
                '%s [%s → %s, project %s%s]: %d%s e-mail %s%s%s%s',
                $outcome->companyName,
                $outcome->mailboxKey,
                $outcome->email,
                $outcome->projectKey,
                $datasetNote,
                $reported,
                $expectedNote,
                $verb,
                $purgedNote,
                $resumeNote,
                $presentNote,
            ));
        }

        if ((bool) $this->option('estimate-cost')) {
            $documents = array_sum(array_map(
                static fn ($outcome): int => $outcome->expected,
                $outcomes,
            ));
            $this->newLine();
            $this->info("Preflight: {$documents} documenti e-mail parent da ingerire.");
            $this->line('Generazione e delivery: 0 chiamate LLM/API.');
            $this->line('Post-ingest AutoWiki/change-analysis: 0 per le fixture v2 generate.');
            $this->line('Embedding: dipende dal provider e dal numero finale di chunk; nessun costo è sostenuto dal preflight.');
        }

        return self::SUCCESS;
    }

    /**
     * Il profilo stress è un test di capacità locale: un account IMAP remoto
     * condiviso richiede decine di migliaia di APPEND seriali.
     *
     * @param  list<string>  $mailboxKeys
     * @param  array<string, mixed>  $manifest
     */
    private function warnIfRemoteStress(
        array $mailboxKeys,
        array $manifest,
        bool $dryRun,
        bool $purgeOnly,
    ): void {
        if ($dryRun || $purgeOnly || ($manifest['profile'] ?? null) !== 'stress') {
            return;
        }

        $statistics = (array) ($manifest['statistics'] ?? []);
        $recordsByMailbox = (array) ($statistics['records_by_mailbox'] ?? []);
        $selectedRecords = array_sum(array_map(
            static fn (string $mailboxKey): int => (int) ($recordsByMailbox[$mailboxKey] ?? 0),
            $mailboxKeys,
        ));

        foreach ($mailboxKeys as $mailboxKey) {
            $config = TestEmailFixtures::configJson($mailboxKey);
            $connection = (array) ($config['connection'] ?? []);
            $host = strtolower(trim((string) ($connection['host'] ?? '')));
            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                continue;
            }

            $this->warn(
                "STRESS SU IMAP REMOTO: {$selectedRecords} APPEND seriali possono richiedere molte ore "
                .'e subire throttling. Per Gmail usa --profile=large; stress è previsto '
                .'su un server IMAP locale usa-e-getta.',
            );

            return;
        }
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;
        if ($minutes < 60) {
            return sprintf('%dm%02ds', $minutes, $remainingSeconds);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh%02dm', $hours, $remainingMinutes);
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

    private function resolveDatasetVersion(string $datasetVersion, string $profile): string
    {
        if ($datasetVersion === '') {
            $datasetVersion = DatasetVersion::standard($profile);
        }

        if (preg_match('/^[a-z0-9-]+$/', $datasetVersion) !== 1) {
            throw new \InvalidArgumentException(
                "Dataset version non valida: '{$datasetVersion}'.",
            );
        }

        return $datasetVersion;
    }

    private function resolveDatasetRoot(): string
    {
        $root = trim((string) $this->option('dataset-root'));
        if ($root === '') {
            throw new \InvalidArgumentException('--dataset-root non può essere vuoto.');
        }

        return str_starts_with($root, DIRECTORY_SEPARATOR) ? $root : base_path($root);
    }

    /**
     * @param  list<string>  $mailboxKeys
     */
    private function operationContext(
        array $mailboxKeys,
        string $actor,
        bool $usesDataset,
        string $datasetVersion,
        ?string $datasetDirectory,
        bool $purgeDataset,
        bool $purgeAll,
        bool $purgeOnly,
        bool $dryRun,
        int $batchSize,
    ): EmailDatasetOperationContext {
        $tenantByMailbox = [];
        foreach ($mailboxKeys as $mailboxKey) {
            $tenantByMailbox[$mailboxKey] = TestEmailFixtures::tenantFor($mailboxKey);
        }

        if ($usesDataset) {
            $manifestPath = rtrim((string) $datasetDirectory, DIRECTORY_SEPARATOR).'/manifest.json';
            $manifestChecksum = hash_file('sha256', $manifestPath);
            if ($manifestChecksum === false) {
                throw new \RuntimeException("Impossibile calcolare il checksum del manifest {$manifestPath}.");
            }
        } else {
            $datasetVersion = 'legacy-curated-v1';
            $parts = [];
            foreach ($mailboxKeys as $mailboxKey) {
                $checksum = hash_file('sha256', TestEmailFixtures::emailsPath($mailboxKey));
                if ($checksum === false) {
                    throw new \RuntimeException("Impossibile calcolare il checksum fixture {$mailboxKey}.");
                }
                $parts[$mailboxKey] = $checksum;
            }
            ksort($parts, SORT_STRING);
            $manifestChecksum = hash(
                'sha256',
                implode("\n", array_map(
                    static fn (string $key, string $checksum): string => "{$key} {$checksum}",
                    array_keys($parts),
                    $parts,
                ))."\n",
            );
        }

        $operation = match (true) {
            $purgeDataset && $purgeOnly => 'purge_dataset_only',
            $purgeDataset => 'purge_dataset_and_append',
            $purgeAll && (bool) $this->option('purge') => 'purge_all_seeded_alias_and_append',
            $purgeAll => 'purge_all_seeded_and_append',
            $dryRun && (bool) $this->option('estimate-cost') => 'estimate_only',
            $dryRun => 'validate_only',
            $usesDataset && (bool) $this->option('resume') => 'resume_dataset',
            $usesDataset => 'append_dataset',
            default => 'append_legacy',
        };

        return new EmailDatasetOperationContext(
            operation: $operation,
            actor: $actor,
            datasetVersion: $datasetVersion,
            manifestChecksum: $manifestChecksum,
            mailboxes: $mailboxKeys,
            tenantByMailbox: $tenantByMailbox,
            parameters: [
                'batch_size' => $batchSize,
                'purge_all_seeded' => $purgeAll,
                'purge_dataset' => $purgeDataset,
                'purge_only' => $purgeOnly,
                'resume' => (bool) $this->option('resume'),
            ],
        );
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = (string) $this->option($name);
        if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
            $this->error("--{$name} deve essere un intero >= 1.");

            return null;
        }

        return (int) $value;
    }
}
