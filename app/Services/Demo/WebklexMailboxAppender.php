<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Services\Demo\Contracts\MailboxAppender;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;

/**
 * Implementazione reale di {@see MailboxAppender} sopra webklex/php-imap.
 *
 * Esercitata solo nei run live (dev/local con caselle Gmail vere): i test del
 * comando bindano un fake. Apre UNA connessione per casella e consuma lo stream
 * dei messaggi (no login-per-messaggio e niente array RFC822 completo in memoria).
 * Niente fallimenti silenziosi — ogni problema
 * (auth, folder assente, append) solleva un'eccezione; gli errori di connessione
 * TRANSITORI fanno retry, quelli di autenticazione fermano subito (R42).
 *
 * Un drop transitorio durante APPEND viene risolto sul singolo messaggio:
 * riconnessione, ricerca del Message-ID deterministico, append solo se assente.
 * Non viene mai ritentato l'intero stream.
 *
 * NOTA R13: questi due metodi colpiscono un server IMAP reale e NON hanno test
 * automatici (solo il fake è esercitato). In particolare `purgeSeeded` è
 * DISTRUTTIVO e il match dell'header custom è verificato solo nei run live.
 */
final class WebklexMailboxAppender implements MailboxAppender
{
    private const CONNECT_ATTEMPTS = 3;

    private const CONNECT_RETRY_DELAY = 5;

    public function appendStream(
        MailboxTarget $target,
        iterable $messages,
        ?Closure $onStored = null,
        ?EmailSeedLockLease $lease = null,
    ): MailboxAppendResult {
        $operation = fn (): MailboxAppendResult => $this->appendStreamUnlocked(
            $target,
            $messages,
            $onStored,
            $lease,
        );

        return $lease === null ? $operation() : $lease->runGuarded($operation);
    }

    private function appendStreamUnlocked(
        MailboxTarget $target,
        iterable $messages,
        ?Closure $onStored,
        ?EmailSeedLockLease $lease,
    ): MailboxAppendResult {
        $lease?->refresh();
        $client = $this->connect($target, $lease);
        $appended = 0;
        $alreadyPresent = 0;

        try {
            // Crea la label/cartella se non esiste (Gmail: un IMAP CREATE crea la
            // label) — su un account-unico-con-label la cartella nasce qui.
            $folder = $this->ensureFolder($client, $target->folder);

            foreach ($messages as $message) {
                if (! $message instanceof PreparedEmailMessage) {
                    throw new InvalidArgumentException(
                        'MailboxAppender accetta solo istanze PreparedEmailMessage.',
                    );
                }

                $lease?->refresh();
                $this->applyLeaseTimeout($client, $lease);
                if ($message->verifyBeforeAppend && $this->messageExists($folder, $message->messageId)) {
                    $lease?->refresh();
                    $alreadyPresent++;
                    if ($onStored !== null) {
                        $onStored($message, true);
                    }

                    continue;
                }

                try {
                    // Refresh immediately before every physical APPEND. The
                    // cache implementation renews only for the current owner.
                    $lease?->refresh();
                    $this->applyLeaseTimeout($client, $lease);
                    $folder->appendMessage($message->raw, null, $message->internalDate);
                    $lease?->refresh();
                    $appended++;
                    if ($onStored !== null) {
                        $onStored($message, false);
                    }
                } catch (Throwable $e) {
                    if ($e instanceof EmailSeedLockLeaseExpiredException) {
                        throw $e;
                    }
                    if ($this->isAuthError($e) || ! $this->isTransientError($e)) {
                        throw $e;
                    }

                    // L'APPEND può essere stato accettato prima del drop. Si
                    // riconnette e si decide sul solo Message-ID corrente.
                    $this->disconnectSilently($client);
                    $client = $this->connect($target, $lease);
                    $folder = $this->ensureFolder($client, $target->folder);

                    $lease?->refresh();
                    $this->applyLeaseTimeout($client, $lease);
                    if ($this->messageExists($folder, $message->messageId)) {
                        $lease?->refresh();
                        $alreadyPresent++;
                        if ($onStored !== null) {
                            $onStored($message, true);
                        }

                        continue;
                    }

                    $lease?->refresh();
                    $this->applyLeaseTimeout($client, $lease);
                    $folder->appendMessage($message->raw, null, $message->internalDate);
                    $lease?->refresh();
                    $appended++;
                    if ($onStored !== null) {
                        $onStored($message, false);
                    }
                }
            }
        } finally {
            $this->disconnectSilently($client);
        }

        return new MailboxAppendResult($appended, $alreadyPresent);
    }

    public function purgeSeeded(
        MailboxTarget $target,
        string $headerName,
        string $value,
        ?EmailSeedLockLease $lease = null,
    ): int {
        $operation = fn (): int => $this->purgeSeededUnlocked(
            $target,
            $headerName,
            $value,
            $lease,
        );

        return $lease === null ? $operation() : $lease->runGuarded($operation);
    }

    private function purgeSeededUnlocked(
        MailboxTarget $target,
        string $headerName,
        string $value,
        ?EmailSeedLockLease $lease,
    ): int {
        if (preg_match('/^[A-Za-z0-9-]+$/', $headerName) !== 1) {
            throw new InvalidArgumentException("Nome header IMAP non valido: '{$headerName}'.");
        }
        if ($value === '' || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException('Valore header IMAP non valido.');
        }

        $lease?->refresh();
        $client = $this->connect($target, $lease);
        $deleted = 0;

        try {
            // Label assente = niente da purgare (primo run): non è un errore.
            $this->applyLeaseTimeout($client, $lease);
            $folder = $client->getFolderByPath($target->folder, false, true);
            if ($folder === null) {
                return 0;
            }

            // Il filtro avviene sul server e i risultati vengono popolati a
            // blocchi: non caricare l'intera mailbox in memoria.
            $folder->query()
                ->whereHeader($headerName, $value)
                ->setFetchBody(false)
                ->leaveUnread()
                ->chunked(function ($messages) use (
                    &$deleted,
                    $client,
                    $headerName,
                    $value,
                    $lease,
                ): void {
                    $needle = strtolower($headerName);

                    foreach ($messages as $message) {
                        $header = $message->getHeader();
                        if ($header === null || (string) $header->get($needle) !== $value) {
                            continue;
                        }

                        $lease?->refresh();
                        $this->applyLeaseTimeout($client, $lease);
                        if (! $message->delete(true)) {
                            throw new RuntimeException(
                                "Eliminazione IMAP fallita per header {$headerName}={$value}.",
                            );
                        }

                        $lease?->refresh();
                        $deleted++;
                    }
                }, 100);
        } finally {
            $this->disconnectSilently($client);
        }

        return $deleted;
    }

    /**
     * Ritorna la cartella/label target, creandola se non esiste. Su Gmail un
     * IMAP CREATE crea la label; con un account-unico-con-label la cartella di
     * una casella nasce al primo seeding.
     */
    private function ensureFolder(Client $client, string $name): Folder
    {
        $folder = $client->getFolderByPath($name, false, true);
        if ($folder === null) {
            // expunge=false: subito dopo CREATE non c'è mailbox selezionata e
            // Gmail risponde "BAD EXPUNGE not allowed now".
            $folder = $client->createFolder($name, false);
        }

        if ($folder === null) {
            throw new RuntimeException("Impossibile creare/aprire la cartella IMAP '{$name}'.");
        }

        return $folder;
    }

    private function messageExists(Folder $folder, string $messageId): bool
    {
        $messageId = trim($messageId, '<>');

        return $folder->query()
            ->whereMessageId($messageId)
            ->setFetchBody(false)
            ->leaveUnread()
            ->limit(1)
            ->get()
            ->isNotEmpty();
    }

    /**
     * Connessione con retry sui soli errori TRANSITORI (R42); l'autenticazione
     * fallita ferma subito (ritentare con le stesse credenziali è inutile).
     */
    private function connect(
        MailboxTarget $target,
        ?EmailSeedLockLease $lease = null,
    ): Client
    {
        $attempt = 0;

        while (true) {
            try {
                $lease?->refresh();
                $client = (new ClientManager)->make([
                    'host' => $target->host,
                    'port' => $target->port,
                    'encryption' => $target->encryption,
                    'validate_cert' => $target->validateCert,
                    'username' => $target->email,
                    'password' => $target->secret,
                    'protocol' => 'imap',
                    // null = basic LOGIN; XOAUTH2 non usato dall'harness di test.
                    'authentication' => null,
                    'timeout' => $lease?->ioTimeoutSeconds() ?? 30,
                ]);

                $client->connect();
                $lease?->refresh();

                return $client;
            } catch (Throwable $e) {
                if ($e instanceof EmailSeedLockLeaseExpiredException) {
                    throw $e;
                }
                if ($this->isAuthError($e) || $attempt >= self::CONNECT_ATTEMPTS - 1) {
                    throw $e;
                }

                $attempt++;
                $lease?->refresh();
                sleep(self::CONNECT_RETRY_DELAY);
            }
        }
    }

    private function applyLeaseTimeout(
        Client $client,
        ?EmailSeedLockLease $lease,
    ): void {
        if ($lease !== null) {
            $client->setTimeout($lease->ioTimeoutSeconds());
        }
    }

    private function isAuthError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        // Solo frasi che indicano un rifiuto REALE delle credenziali. NON usare
        // né il bare 'login' né 'login failed': errori transitori li contengono
        // ('login server timeout', 'LOGIN failed: temporary system problem') e
        // andrebbero ritentati (R42), non fermati. Il rifiuto credenziali di
        // Gmail/Dovecot è già coperto da 'authenticationfailed' /
        // 'authentication failed' / 'invalid credential'.
        foreach (['authenticationfailed', 'authentication failed', 'invalid credential', 'permission denied'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isTransientError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach ([
            'broken pipe',
            'connection reset',
            'connection closed',
            'connection lost',
            'timed out',
            'timeout',
            'temporary',
            'server unavailable',
            'end of file',
            'unexpected eof',
            'socket',
            'ssl',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return str_contains(strtolower($e::class), 'connection');
    }

    private function disconnectSilently(Client $client): void
    {
        try {
            $client->disconnect();
        } catch (Throwable) {
            // A failed/disappeared socket is already disconnected. This
            // best-effort cleanup must not hide the original APPEND failure.
        }
    }
}
