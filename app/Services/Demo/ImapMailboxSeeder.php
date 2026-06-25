<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Services\Demo\Contracts\MailboxAppender;
use Carbon\Carbon;
use Closure;
use Database\Seeders\TestEmailFixtures;
use InvalidArgumentException;

/**
 * Orchestratore del seeding e-mail: per ogni casella costruisce i messaggi dalle
 * fixtures e li inietta nella INBOX via {@see MailboxAppender} in un unico batch
 * (una connessione per casella — robusto con 100+ e-mail).
 *
 * Decisioni chiave:
 *   - INTERNALDATE = now() ad ogni APPEND, così i messaggi (datati nel 2024 nelle
 *     fixtures) ricadono comunque nella finestra `date_window_days` del connettore.
 *   - `--purge` (opzionale, distruttivo) elimina prima i messaggi marcati con
 *     l'header {@see TestEmailFixtures::SEED_HEADER}, rendendo i re-run idempotenti.
 *   - R14/R4: password mancante o errore IMAP → eccezione, mai esito silenzioso.
 *   - R42: il retry sugli errori di connessione TRANSITORI vive nell'appender
 *     reale ({@see WebklexMailboxAppender}); l'auth fallita ferma subito.
 */
final class ImapMailboxSeeder
{
    public function __construct(
        private readonly MailboxAppender $appender,
        private readonly EmailMessageBuilder $builder,
    ) {}

    /**
     * @param  list<string>  $mailboxKeys  caselle da popolare (devono esistere nelle fixtures)
     * @param  Closure(string, int, string): void|null  $onMessage  callback (mailboxKey, index, subject)
     * @return list<SeedOutcome>
     */
    public function seed(
        array $mailboxKeys,
        bool $dryRun = false,
        bool $purge = false,
        ?Closure $onMessage = null,
    ): array {
        $outcomes = [];

        foreach ($mailboxKeys as $mailboxKey) {
            $outcomes[] = $this->seedOne($mailboxKey, $dryRun, $purge, $onMessage);
        }

        return $outcomes;
    }

    private function seedOne(
        string $mailboxKey,
        bool $dryRun,
        bool $purge,
        ?Closure $onMessage,
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

        // Costruisce tutti i messaggi (parte pura, valida il builder anche in dry-run).
        $raws = [];
        foreach ($emails as $index => $fixture) {
            $raws[] = $this->builder->build($target, $fixture);
            if ($onMessage !== null) {
                $onMessage($mailboxKey, (int) $index, (string) $fixture['subject']);
            }
        }

        if ($dryRun) {
            return new SeedOutcome($mailboxKey, $target->projectKey, $target->companyName, $target->email, count($raws), 0, true);
        }

        // Opzionale purge (idempotenza re-run), poi APPEND in un solo batch.
        $purged = $purge
            ? $this->appender->purgeSeeded($target, TestEmailFixtures::SEED_HEADER, $target->mailboxKey)
            : 0;

        $appended = $this->appender->appendBatch($target, $raws, Carbon::now());

        return new SeedOutcome($mailboxKey, $target->projectKey, $target->companyName, $target->email, $appended, $purged, false);
    }
}
