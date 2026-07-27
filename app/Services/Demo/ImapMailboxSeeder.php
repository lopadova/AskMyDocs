<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Services\Demo\Contracts\MailboxAppender;
use App\Services\Demo\EmailDataset\EmailDatasetReader;
use Closure;
use Database\Seeders\TestEmailFixtures;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orchestratore del seeding e-mail: per ogni casella costruisce i messaggi dalle
 * fixtures e li inietta nella INBOX via {@see MailboxAppender} come stream
 * memory-bounded (una connessione per casella).
 *
 * Decisioni chiave:
 *   - INTERNALDATE = now() ad ogni APPEND, così un'installazione incrementale
 *     vede anche messaggi con Date narrativa storica.
 *   - `--purge` (opzionale, distruttivo) elimina prima i messaggi marcati con
 *     l'header {@see TestEmailFixtures::SEED_HEADER}, rendendo i re-run idempotenti.
 *   - R14/R4: password mancante o errore IMAP → eccezione, mai esito silenzioso.
 *   - R42: il retry sugli errori di connessione TRANSITORI vive nell'appender
 *     reale ({@see WebklexMailboxAppender}); l'auth fallita ferma subito.
 */
final class ImapMailboxSeeder
{
    public const PROGRESS_WAITING_LOCK = 'waiting_lock';

    public const PROGRESS_LOCK_ACQUIRED = 'lock_acquired';

    public const PROGRESS_PURGE_RECOVERY_STARTED = 'purge_recovery_started';

    public const PROGRESS_PURGE_STARTED = 'purge_started';

    public const PROGRESS_PURGE_DELETED = 'purge_deleted';

    public const PROGRESS_PURGE_COMPLETED = 'purge_completed';

    public const PROGRESS_APPEND_STARTED = 'append_started';

    public const PROGRESS_APPEND_STORED = 'append_stored';

    public function __construct(
        private readonly MailboxAppender $appender,
        private readonly EmailMessageBuilder $builder,
        private readonly EmailDatasetReader $datasetReader,
        private readonly EmailSeedCheckpointStore $checkpoints,
        private readonly EmailSeedMailboxLock $mailboxLock,
    ) {}

    /**
     * @param  list<string>  $mailboxKeys  caselle da popolare (devono esistere nelle fixtures)
     * @param  Closure(string, int, string): void|null  $onMessage  callback (mailboxKey, index, subject)
     * @param  Closure(string, string, int, int|null): void|null  $onProgress
     * @return list<SeedOutcome>
     */
    public function seed(
        array $mailboxKeys,
        bool $dryRun = false,
        bool $purge = false,
        ?Closure $onMessage = null,
        ?Closure $onProgress = null,
    ): array {
        $outcomes = [];

        foreach ($mailboxKeys as $mailboxKey) {
            $outcomes[] = $this->seedOne(
                $mailboxKey,
                $dryRun,
                $purge,
                $onMessage,
                $onProgress,
            );
        }

        return $outcomes;
    }

    /**
     * Streams a generated schema-v2 dataset into the selected mailboxes.
     *
     * @param  Closure(string, int, string): void|null  $onMessage
     * @param  Closure(string, string, int, int|null): void|null  $onProgress
     * @return list<SeedOutcome>
     */
    public function seedDataset(
        EmailDatasetSeedRequest $request,
        ?Closure $onMessage = null,
        ?Closure $onProgress = null,
    ): array {
        if ($request->checkpointEvery < 1) {
            throw new InvalidArgumentException('checkpointEvery deve essere almeno 1.');
        }
        if ($request->purgeDataset && $request->purgeAllSeeded) {
            throw new InvalidArgumentException(
                'Scegli purgeDataset oppure purgeAllSeeded, non entrambi.',
            );
        }
        if ($request->purgeOnly && ! $request->purgeDataset) {
            throw new InvalidArgumentException(
                'purgeOnly richiede purgeDataset per mantenere lo scope sulla versione.',
            );
        }

        $manifest = $this->datasetReader->manifest($request->datasetDirectory);
        $datasetVersion = (string) $manifest['dataset_version'];
        $manifestPath = rtrim($request->datasetDirectory, DIRECTORY_SEPARATOR).'/manifest.json';
        $manifestChecksum = hash_file('sha256', $manifestPath);
        if ($manifestChecksum === false) {
            throw new RuntimeException("Impossibile calcolare il checksum del manifest: {$manifestPath}");
        }

        // Validate the complete selection, dataset coverage and credentials
        // before the first purge/APPEND. A bad second mailbox must never leave
        // an already-processed first mailbox partially mutated.
        $mailboxes = [];
        foreach (array_values(array_unique($request->mailboxKeys)) as $mailboxKey) {
            $mailboxes[] = [
                'key' => $mailboxKey,
                'target' => $this->target($mailboxKey, $request->dryRun),
                'expected' => $this->datasetMailboxCount(
                    $request->datasetDirectory,
                    $mailboxKey,
                ),
            ];
        }

        $outcomes = [];
        foreach ($mailboxes as $mailbox) {
            $outcomes[] = $this->seedDatasetMailbox(
                request: $request,
                mailboxKey: $mailbox['key'],
                target: $mailbox['target'],
                expected: $mailbox['expected'],
                datasetVersion: $datasetVersion,
                manifestChecksum: $manifestChecksum,
                onMessage: $onMessage,
                onProgress: $onProgress,
            );
        }

        return $outcomes;
    }

    private function seedOne(
        string $mailboxKey,
        bool $dryRun,
        bool $purge,
        ?Closure $onMessage,
        ?Closure $onProgress,
    ): SeedOutcome {
        if (! in_array($mailboxKey, TestEmailFixtures::mailboxKeys(), true)) {
            throw new InvalidArgumentException(
                "mailbox '{$mailboxKey}' non definita in TestEmailFixtures (attese: "
                .implode(', ', TestEmailFixtures::mailboxKeys()).').',
            );
        }

        $mailbox = TestEmailFixtures::mailbox($mailboxKey);
        $config = TestEmailFixtures::configJson($mailboxKey);
        $connection = (array) ($config['connection'] ?? []);
        $folders = (array) ($config['folders']['include'] ?? ['INBOX']);
        $folder = (string) ($folders[0] ?? 'INBOX');
        $emails = TestEmailFixtures::emailsForMailbox($mailboxKey);

        // In dry-run NON serve (né si legge) la password: si valida solo la
        // costruzione dei messaggi senza toccare la rete.
        $secret = $dryRun ? '' : TestEmailFixtures::passwordFor($mailboxKey);

        $target = new MailboxTarget(
            mailboxKey: $mailboxKey,
            projectKey: (string) $mailbox['project_key'],
            companyName: (string) $mailbox['company_name'],
            email: (string) $mailbox['email'],
            host: (string) ($connection['host'] ?? 'imap.gmail.com'),
            port: (int) ($connection['port'] ?? 993),
            encryption: (string) ($connection['encryption'] ?? 'ssl'),
            validateCert: (bool) ($connection['validate_cert'] ?? true),
            secret: $secret,
            folder: $folder,
        );

        // Il generator costruisce un solo RFC822 alla volta. Le fixture legacy
        // sono ancora un array JSON, ma la parte pesante MIME rimane bounded.
        $messages = function (
            ?EmailSeedLockLease $lease = null,
        ) use ($emails, $target, $onMessage, $mailboxKey): iterable {
            foreach ($emails as $index => $fixture) {
                $lease?->refresh();
                $sequence = (int) $index + 1;
                $message = $this->builder->prepare($target, $fixture, $sequence);

                if ($onMessage !== null) {
                    $onMessage($mailboxKey, (int) $index, $message->subject);
                }

                $lease?->assertCanAppend();
                yield $message;
            }
        };

        if ($dryRun) {
            $validated = 0;
            foreach ($messages() as $_message) {
                $validated++;
            }

            return new SeedOutcome(
                $mailboxKey,
                $target->projectKey,
                $target->companyName,
                $target->email,
                $validated,
                0,
                true,
            );
        }

        // Opzionale purge (idempotenza re-run), poi APPEND in un solo batch.
        $this->reportProgress(
            $onProgress,
            $mailboxKey,
            self::PROGRESS_WAITING_LOCK,
            0,
            count($emails),
        );

        return $this->mailboxLock->run(
            $target,
            function (EmailSeedLockLease $lease) use (
                $target,
                $purge,
                $messages,
                $mailboxKey,
                $emails,
                $onProgress,
            ): SeedOutcome {
                $this->reportProgress(
                    $onProgress,
                    $mailboxKey,
                    self::PROGRESS_LOCK_ACQUIRED,
                    0,
                    count($emails),
                );

                [$purged, $recoveredPurge] = $this->recoverPendingPurge(
                    $target,
                    $lease,
                    $onProgress,
                );
                if ($purge) {
                    $requestedPurge = EmailSeedPurgeIntent::allSeeded($target->mailboxKey);
                    if (
                        $recoveredPurge === null
                        || ! $recoveredPurge->isSameRequest($requestedPurge)
                    ) {
                        $purged += $this->executeNewPurge(
                            $target,
                            $requestedPurge,
                            $lease,
                            $onProgress,
                        );
                    }
                }

                $this->reportProgress(
                    $onProgress,
                    $mailboxKey,
                    self::PROGRESS_APPEND_STARTED,
                    0,
                    count($emails),
                );
                $stored = 0;
                $result = $this->appender->appendStream(
                    $target,
                    $messages($lease),
                    function (
                        PreparedEmailMessage $_message,
                        bool $_alreadyPresent,
                    ) use (
                        &$stored,
                        $emails,
                        $lease,
                        $mailboxKey,
                        $onProgress,
                    ): void {
                        $lease->refresh();
                        $stored++;
                        $this->reportProgress(
                            $onProgress,
                            $mailboxKey,
                            self::PROGRESS_APPEND_STORED,
                            $stored,
                            count($emails),
                        );
                    },
                    $lease,
                );

                return new SeedOutcome(
                    $mailboxKey,
                    $target->projectKey,
                    $target->companyName,
                    $target->email,
                    $result->appended,
                    $purged,
                    false,
                    $result->alreadyPresent,
                    count($emails),
                );
            },
        );
    }

    private function seedDatasetMailbox(
        EmailDatasetSeedRequest $request,
        string $mailboxKey,
        MailboxTarget $target,
        int $expected,
        string $datasetVersion,
        string $manifestChecksum,
        ?Closure $onMessage,
        ?Closure $onProgress,
    ): SeedOutcome {
        if ($request->dryRun) {
            $validated = 0;
            foreach ($this->datasetReader->recordsForMailbox($request->datasetDirectory, $mailboxKey) as $record) {
                $validated++;
                $message = $this->builder->prepare($target, $record, $validated);
                if ($onMessage !== null) {
                    $onMessage($mailboxKey, $validated - 1, $message->subject);
                }
            }

            if ($validated !== $expected) {
                throw new RuntimeException(
                    "Dataset {$datasetVersion}/{$mailboxKey}: attesi {$expected}, validati {$validated}.",
                );
            }

            return new SeedOutcome(
                mailboxKey: $mailboxKey,
                projectKey: $target->projectKey,
                companyName: $target->companyName,
                email: $target->email,
                appended: $validated,
                purged: 0,
                dryRun: true,
                expected: $expected,
                datasetVersion: $datasetVersion,
            );
        }

        $this->reportProgress(
            $onProgress,
            $mailboxKey,
            self::PROGRESS_WAITING_LOCK,
            0,
            $expected,
        );

        return $this->mailboxLock->run(
            $target,
            fn (EmailSeedLockLease $lease): SeedOutcome => $this->seedDatasetMailboxLocked(
                request: $request,
                mailboxKey: $mailboxKey,
                target: $target,
                expected: $expected,
                datasetVersion: $datasetVersion,
                manifestChecksum: $manifestChecksum,
                onMessage: $onMessage,
                onProgress: $onProgress,
                lease: $lease,
            ),
        );
    }

    private function seedDatasetMailboxLocked(
        EmailDatasetSeedRequest $request,
        string $mailboxKey,
        MailboxTarget $target,
        int $expected,
        string $datasetVersion,
        string $manifestChecksum,
        ?Closure $onMessage,
        ?Closure $onProgress,
        EmailSeedLockLease $lease,
    ): SeedOutcome {
        $this->reportProgress(
            $onProgress,
            $mailboxKey,
            self::PROGRESS_LOCK_ACQUIRED,
            0,
            $expected,
        );

        // A persisted intent makes every prior checkpoint untrustworthy until
        // the same remote purge is replayed and its local cleanup completes.
        // Recover before honoring a new purge request or reading a checkpoint.
        [$purged, $recoveredPurge] = $this->recoverPendingPurge(
            $target,
            $lease,
            $onProgress,
        );
        $requestedPurge = null;
        if ($request->purgeAllSeeded) {
            $requestedPurge = EmailSeedPurgeIntent::allSeeded(
                mailboxKey: $target->mailboxKey,
                datasetVersion: $datasetVersion,
                manifestChecksum: $manifestChecksum,
            );
        } elseif ($request->purgeDataset) {
            $requestedPurge = EmailSeedPurgeIntent::dataset(
                mailboxKey: $target->mailboxKey,
                datasetVersion: $datasetVersion,
                manifestChecksum: $manifestChecksum,
            );
        }
        if (
            $requestedPurge !== null
            && (
                $recoveredPurge === null
                || ! $recoveredPurge->isSameRequest($requestedPurge)
            )
        ) {
            $purged += $this->executeNewPurge(
                $target,
                $requestedPurge,
                $lease,
                $onProgress,
            );
        }

        if ($request->purgeOnly) {
            return new SeedOutcome(
                mailboxKey: $mailboxKey,
                projectKey: $target->projectKey,
                companyName: $target->companyName,
                email: $target->email,
                appended: 0,
                purged: $purged,
                dryRun: false,
                expected: $expected,
                datasetVersion: $datasetVersion,
            );
        }

        $hasCheckpoint = $this->checkpoints->exists($target, $datasetVersion);
        if ($hasCheckpoint && ! $request->resume && ! $request->purgeDataset && ! $request->purgeAllSeeded) {
            throw new RuntimeException(
                "Esiste già un checkpoint per {$mailboxKey}/{$datasetVersion}: "
                .'usa --resume oppure un purge esplicito.',
            );
        }

        $checkpoint = $this->checkpoints->load($target, $datasetVersion, $manifestChecksum);
        if ($checkpoint->lastSequence > $expected) {
            throw new RuntimeException(
                "Checkpoint {$mailboxKey}/{$datasetVersion} oltre il totale manifest ({$expected}).",
            );
        }

        $resumed = $checkpoint->lastSequence;
        if ($resumed === $expected) {
            return new SeedOutcome(
                mailboxKey: $mailboxKey,
                projectKey: $target->projectKey,
                companyName: $target->companyName,
                email: $target->email,
                appended: 0,
                purged: $purged,
                dryRun: false,
                expected: $expected,
                resumed: $resumed,
                datasetVersion: $datasetVersion,
            );
        }

        $lastSavedSequence = $checkpoint->lastSequence;
        $uncertainUntil = $resumed + $request->checkpointEvery;
        $this->reportProgress(
            $onProgress,
            $mailboxKey,
            self::PROGRESS_APPEND_STARTED,
            $resumed,
            $expected,
        );
        $messages = function () use (
            $request,
            $target,
            $mailboxKey,
            $resumed,
            $uncertainUntil,
            $onMessage,
            $lease,
        ): iterable {
            $sequence = 0;
            foreach ($this->datasetReader->recordsForMailbox($request->datasetDirectory, $mailboxKey) as $record) {
                $sequence++;
                if ($sequence <= $resumed) {
                    continue;
                }

                $lease->refresh();
                $message = $this->builder->prepare(
                    target: $target,
                    fixture: $record,
                    sequence: $sequence,
                    // At most one checkpoint interval can have reached the
                    // server before an abrupt process death. Verify that window
                    // by stable Message-ID, then continue with plain APPEND.
                    verifyBeforeAppend: $request->resume && $sequence <= $uncertainUntil,
                );

                if ($onMessage !== null) {
                    $onMessage($mailboxKey, $sequence - 1, $message->subject);
                }

                $lease->assertCanAppend();
                yield $message;
            }
        };

        $result = null;
        try {
            $result = $this->appender->appendStream(
                $target,
                $messages(),
                function (PreparedEmailMessage $message, bool $alreadyPresent) use (
                    &$checkpoint,
                    &$lastSavedSequence,
                    $target,
                    $request,
                    $lease,
                    $mailboxKey,
                    $onProgress,
                    $expected,
                ): void {
                    // Refresh first: a remote ACK received after ownership loss
                    // is intentionally left outside the contiguous checkpoint.
                    // Resume verifies its deterministic Message-ID.
                    $lease->refresh();
                    $checkpoint = $checkpoint->advance($message, $alreadyPresent);
                    $this->reportProgress(
                        $onProgress,
                        $mailboxKey,
                        self::PROGRESS_APPEND_STORED,
                        $checkpoint->lastSequence,
                        $expected,
                    );

                    if (
                        $checkpoint->lastSequence - $lastSavedSequence
                        >= $request->checkpointEvery
                    ) {
                        $lease->assertCanPersistCheckpoint();
                        $this->checkpoints->save($target, $checkpoint);
                        $lastSavedSequence = $checkpoint->lastSequence;
                    }
                },
                $lease,
            );
        } finally {
            // Persist all progress acknowledged by the appender, including the
            // tail before an exception. A hard process kill may lose at most
            // checkpointEvery-1 acknowledgements; resume verifies that window.
            if ($checkpoint->lastSequence > $lastSavedSequence) {
                $lease->runGuarded(function () use (
                    &$lastSavedSequence,
                    $checkpoint,
                    $lease,
                    $target,
                ): void {
                    $lease->refresh();
                    $lease->assertCanPersistCheckpoint();
                    $this->checkpoints->save($target, $checkpoint);
                    $lastSavedSequence = $checkpoint->lastSequence;
                });
            }
        }

        if ($result === null) {
            throw new RuntimeException("Seeding dataset non completato per {$mailboxKey}.");
        }
        if ($checkpoint->lastSequence !== $expected) {
            throw new RuntimeException(
                "Seeding incompleto per {$mailboxKey}: {$checkpoint->lastSequence}/{$expected}.",
            );
        }

        return new SeedOutcome(
            mailboxKey: $mailboxKey,
            projectKey: $target->projectKey,
            companyName: $target->companyName,
            email: $target->email,
            appended: $result->appended,
            purged: $purged,
            dryRun: false,
            alreadyPresent: $result->alreadyPresent,
            expected: $expected,
            resumed: $resumed,
            datasetVersion: $datasetVersion,
        );
    }

    /**
     * @return array{int, EmailSeedPurgeIntent|null}
     */
    private function recoverPendingPurge(
        MailboxTarget $target,
        EmailSeedLockLease $lease,
        ?Closure $onProgress,
    ): array {
        $intent = $lease->runGuarded(function () use ($lease, $target): ?EmailSeedPurgeIntent {
            $lease->refresh();
            $intent = $this->checkpoints->pendingPurge($target);
            $lease->assertCanPersistCheckpoint();

            return $intent;
        });

        if ($intent === null) {
            return [0, null];
        }

        $this->reportProgress(
            $onProgress,
            $target->mailboxKey,
            self::PROGRESS_PURGE_RECOVERY_STARTED,
            0,
            null,
        );

        return [
            $this->executePersistedPurge($target, $intent, $lease, $onProgress),
            $intent,
        ];
    }

    private function executeNewPurge(
        MailboxTarget $target,
        EmailSeedPurgeIntent $intent,
        EmailSeedLockLease $lease,
        ?Closure $onProgress,
    ): int {
        $lease->runGuarded(function () use ($intent, $lease, $target): void {
            $lease->refresh();
            $lease->assertCanPersistCheckpoint();
            $this->checkpoints->beginPurge($target, $intent);
            $lease->assertCanPersistCheckpoint();
        });

        $this->reportProgress(
            $onProgress,
            $target->mailboxKey,
            self::PROGRESS_PURGE_STARTED,
            0,
            null,
        );

        return $this->executePersistedPurge($target, $intent, $lease, $onProgress);
    }

    private function executePersistedPurge(
        MailboxTarget $target,
        EmailSeedPurgeIntent $intent,
        EmailSeedLockLease $lease,
        ?Closure $onProgress,
    ): int {
        $purged = $this->appender->purgeSeeded(
            $target,
            $intent->headerName,
            $intent->headerValue,
            $lease,
            function (int $deleted) use ($onProgress, $target): void {
                $this->reportProgress(
                    $onProgress,
                    $target->mailboxKey,
                    self::PROGRESS_PURGE_DELETED,
                    $deleted,
                    null,
                );
            },
        );

        $lease->runGuarded(function () use ($intent, $lease, $target): void {
            $lease->refresh();
            $lease->assertCanPersistCheckpoint();
            if ($intent->clearsEveryCheckpoint()) {
                $this->checkpoints->clearAll($target);
            } else {
                $datasetVersion = $intent->datasetVersion;
                if ($datasetVersion === null) {
                    throw new RuntimeException(
                        'Purge intent dataset privo della dataset version.',
                    );
                }
                $this->checkpoints->clear($target, $datasetVersion);
            }
            $lease->assertCanPersistCheckpoint();
            $this->checkpoints->completePurge($target);
            $lease->assertCanPersistCheckpoint();
        });

        $this->reportProgress(
            $onProgress,
            $target->mailboxKey,
            self::PROGRESS_PURGE_COMPLETED,
            $purged,
            null,
        );

        return $purged;
    }

    /**
     * @param  Closure(string, string, int, int|null): void|null  $onProgress
     */
    private function reportProgress(
        ?Closure $onProgress,
        string $mailboxKey,
        string $phase,
        int $current,
        ?int $total,
    ): void {
        if ($onProgress !== null) {
            $onProgress($mailboxKey, $phase, $current, $total);
        }
    }

    private function datasetMailboxCount(string $datasetDirectory, string $mailboxKey): int
    {
        $manifest = $this->datasetReader->manifest($datasetDirectory);
        $count = $manifest['statistics']['records_by_mailbox'][$mailboxKey] ?? null;
        if (! is_int($count) || $count < 1) {
            throw new InvalidArgumentException(
                "Mailbox {$mailboxKey} assente dal manifest dataset.",
            );
        }

        return $count;
    }

    private function target(string $mailboxKey, bool $dryRun): MailboxTarget
    {
        if (! in_array($mailboxKey, TestEmailFixtures::mailboxKeys(), true)) {
            throw new InvalidArgumentException(
                "mailbox '{$mailboxKey}' non definita in TestEmailFixtures (attese: "
                .implode(', ', TestEmailFixtures::mailboxKeys()).').',
            );
        }

        $mailbox = TestEmailFixtures::mailbox($mailboxKey);
        $config = TestEmailFixtures::configJson($mailboxKey);
        $connection = (array) ($config['connection'] ?? []);
        $folders = (array) ($config['folders']['include'] ?? ['INBOX']);

        return new MailboxTarget(
            mailboxKey: $mailboxKey,
            projectKey: (string) $mailbox['project_key'],
            companyName: (string) $mailbox['company_name'],
            email: (string) $mailbox['email'],
            host: (string) ($connection['host'] ?? 'imap.gmail.com'),
            port: (int) ($connection['port'] ?? 993),
            encryption: (string) ($connection['encryption'] ?? 'ssl'),
            validateCert: (bool) ($connection['validate_cert'] ?? true),
            secret: $dryRun ? '' : TestEmailFixtures::passwordFor($mailboxKey),
            folder: (string) ($folders[0] ?? 'INBOX'),
        );
    }
}
