<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Services\Demo\Contracts\MailboxAppender;
use Carbon\Carbon;
use Closure;
use Database\Seeders\TestEmailFixtures;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Orchestratore del seeding e-mail: per ogni azienda costruisce i messaggi
 * dalle fixtures e li inietta nella casella IMAP via {@see MailboxAppender}.
 *
 * Decisioni chiave:
 *   - INTERNALDATE = now() ad ogni APPEND, così i messaggi (datati nel 2024 nelle
 *     fixtures) ricadono comunque nella finestra `date_window_days` del connettore.
 *   - `--purge` (opzionale, distruttivo) elimina prima i messaggi marcati con
 *     l'header {@see TestEmailFixtures::SEED_HEADER}, rendendo i re-run idempotenti.
 *   - R14/R4: password mancante o errore IMAP → eccezione, mai esito silenzioso.
 *   - R42: gli errori IMAP TRANSITORI (connessione/timeout) fanno attesa+retry;
 *     gli errori di autenticazione sono permanenti e fermano subito.
 */
final class ImapMailboxSeeder
{
    public function __construct(
        private readonly MailboxAppender $appender,
        private readonly EmailMessageBuilder $builder,
    ) {}

    /**
     * @param  list<string>  $projectKeys  project_key da popolare (devono esistere nelle fixtures)
     * @param  Closure(string, int, string): void|null  $onMessage  callback (projectKey, index, subject)
     * @return list<SeedOutcome>
     */
    public function seed(
        array $projectKeys,
        bool $dryRun = false,
        bool $purge = false,
        int $retries = 2,
        int $retryDelaySeconds = 60,
        ?Closure $onMessage = null,
    ): array {
        $outcomes = [];

        foreach ($projectKeys as $projectKey) {
            $outcomes[] = $this->seedOne($projectKey, $dryRun, $purge, $retries, $retryDelaySeconds, $onMessage);
        }

        return $outcomes;
    }

    private function seedOne(
        string $projectKey,
        bool $dryRun,
        bool $purge,
        int $retries,
        int $retryDelaySeconds,
        ?Closure $onMessage,
    ): SeedOutcome {
        if (! in_array($projectKey, TestEmailFixtures::projectKeys(), true)) {
            throw new InvalidArgumentException(
                "project_key '{$projectKey}' non definito in TestEmailFixtures (attesi: "
                .implode(', ', TestEmailFixtures::projectKeys()).').',
            );
        }

        $account = TestEmailFixtures::account($projectKey);
        $config = TestEmailFixtures::configJson($projectKey);
        $connection = (array) ($config['connection'] ?? []);
        $folders = (array) ($config['folders']['include'] ?? ['INBOX']);
        $folder = (string) ($folders[0] ?? 'INBOX');
        $emails = TestEmailFixtures::emailsFor($projectKey);

        // In dry-run NON serve (né si legge) la password: si valida solo la
        // costruzione dei messaggi senza toccare la rete.
        $secret = $dryRun ? '' : TestEmailFixtures::passwordFor($projectKey);

        $target = new MailboxTarget(
            projectKey: $projectKey,
            companyName: (string) $account['company_name'],
            email: (string) $account['email'],
            host: (string) ($connection['host'] ?? 'imap.gmail.com'),
            port: (int) ($connection['port'] ?? 993),
            encryption: (string) ($connection['encryption'] ?? 'ssl'),
            validateCert: (bool) ($connection['validate_cert'] ?? true),
            secret: $secret,
            folder: $folder,
        );

        if ($dryRun) {
            // Costruisce ogni messaggio (valida il builder) ma non invia nulla.
            foreach ($emails as $index => $fixture) {
                $this->builder->build($target, $fixture);
                if ($onMessage !== null) {
                    $onMessage($projectKey, (int) $index, (string) $fixture['subject']);
                }
            }

            return new SeedOutcome($projectKey, $target->companyName, $target->email, count($emails), 0, true);
        }

        return $this->withRetry($retries, $retryDelaySeconds, function () use ($target, $emails, $purge, $onMessage): SeedOutcome {
            $purged = 0;
            if ($purge) {
                $purged = $this->appender->purgeSeeded($target, TestEmailFixtures::SEED_HEADER, $target->projectKey);
            }

            $appended = 0;
            foreach ($emails as $index => $fixture) {
                $raw = $this->builder->build($target, $fixture);
                $this->appender->append($target, $raw, Carbon::now());
                $appended++;
                if ($onMessage !== null) {
                    $onMessage($target->projectKey, (int) $index, (string) $fixture['subject']);
                }
            }

            return new SeedOutcome($target->projectKey, $target->companyName, $target->email, $appended, $purged, false);
        });
    }

    /**
     * Esegue l'operazione IMAP con retry sugli errori transitori (R42).
     *
     * @template T
     *
     * @param  Closure(): T  $op
     * @return T
     */
    private function withRetry(int $retries, int $retryDelaySeconds, Closure $op): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $op();
            } catch (Throwable $e) {
                if (! $this->isTransient($e) || $attempt >= $retries) {
                    throw $e;
                }

                $attempt++;
                $this->pause($retryDelaySeconds);
            }
        }
    }

    /**
     * Distingue gli errori IMAP recuperabili (rete) da quelli permanenti (auth).
     */
    private function isTransient(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        // Auth/credenziali/permessi → permanente: ritentare non serve.
        foreach (['authenticat', 'login', 'credential', 'permission denied', 'invalid'] as $permanent) {
            if (str_contains($message, $permanent)) {
                return false;
            }
        }

        // Connessione/timeout/rete → transitorio.
        foreach (['connect', 'timeout', 'timed out', 'network', 'temporar', 'unreachable', 'reset by peer', 'broken pipe'] as $transient) {
            if (str_contains($message, $transient)) {
                return true;
            }
        }

        // Sconosciuto: non ritentare alla cieca (un APPEND parziale è peggio).
        return false;
    }

    /**
     * Pausa tra i retry. Estratta per poter essere sovrascritta nei test.
     */
    protected function pause(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
