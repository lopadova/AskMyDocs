<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Services\Demo\Contracts\MailboxAppender;
use DateTimeInterface;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Implementazione reale di {@see MailboxAppender} sopra webklex/php-imap.
 *
 * Esercitata solo nei run live (dev/local con caselle Gmail vere): i test del
 * comando bindano un fake. Apre UNA connessione per casella e ci appende tutti i
 * messaggi (no login-per-messaggio). Niente fallimenti silenziosi — ogni problema
 * (auth, folder assente, append) solleva un'eccezione; gli errori di connessione
 * TRANSITORI fanno retry, quelli di autenticazione fermano subito (R42).
 */
final class WebklexMailboxAppender implements MailboxAppender
{
    private const CONNECT_ATTEMPTS = 3;

    private const CONNECT_RETRY_DELAY = 5;

    public function appendBatch(MailboxTarget $target, array $rfc822Messages, DateTimeInterface $internalDate): int
    {
        $client = $this->connect($target);
        $appended = 0;

        try {
            $folder = $client->getFolder($target->folder);
            if ($folder === null) {
                throw new RuntimeException(
                    "Cartella IMAP '{$target->folder}' non trovata su {$target->email}.",
                );
            }

            foreach ($rfc822Messages as $raw) {
                // INTERNALDATE = $internalDate (now()) così i messaggi cadono nella
                // finestra date_window_days del connettore anche con Date: passato.
                $folder->appendMessage($raw, null, $internalDate);
                $appended++;
            }
        } finally {
            $client->disconnect();
        }

        return $appended;
    }

    public function purgeSeeded(MailboxTarget $target, string $headerName, string $value): int
    {
        $client = $this->connect($target);
        $deleted = 0;

        try {
            $folder = $client->getFolder($target->folder);
            if ($folder === null) {
                throw new RuntimeException(
                    "Cartella IMAP '{$target->folder}' non trovata su {$target->email}.",
                );
            }

            // `all()` imposta il criterio SEARCH ALL: senza un criterio Gmail
            // risponde "BAD Could not parse command".
            $messages = $folder->query()
                ->all()
                ->setFetchBody(false)
                ->leaveUnread()
                ->get();

            $needle = strtolower($headerName);

            foreach ($messages as $message) {
                $header = $message->getHeader();
                if ($header === null) {
                    continue;
                }

                if ((string) $header->get($needle) !== $value) {
                    continue;
                }

                if ($message->delete(true)) {
                    $deleted++;
                }
            }
        } finally {
            $client->disconnect();
        }

        return $deleted;
    }

    /**
     * Connessione con retry sui soli errori TRANSITORI (R42); l'autenticazione
     * fallita ferma subito (ritentare con le stesse credenziali è inutile).
     */
    private function connect(MailboxTarget $target): Client
    {
        $attempt = 0;

        while (true) {
            try {
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
                ]);

                $client->connect();

                return $client;
            } catch (Throwable $e) {
                if ($this->isAuthError($e) || $attempt >= self::CONNECT_ATTEMPTS - 1) {
                    throw $e;
                }

                $attempt++;
                sleep(self::CONNECT_RETRY_DELAY);
            }
        }
    }

    private function isAuthError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['authenticat', 'invalid credential', 'login', 'permission denied'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
