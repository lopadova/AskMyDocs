<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Services\Demo\Contracts\MailboxAppender;
use DateTimeInterface;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;

/**
 * Implementazione reale di {@see MailboxAppender} sopra webklex/php-imap.
 *
 * Esercitata solo nei run live (dev/local con caselle Gmail vere): i test del
 * comando bindano un fake. Niente fallimenti silenziosi — ogni problema (folder
 * assente, connessione, append) solleva un'eccezione che il seeder classifica
 * come transitoria (retry, R42) o permanente (stop).
 */
final class WebklexMailboxAppender implements MailboxAppender
{
    public function append(MailboxTarget $target, string $rawRfc822, DateTimeInterface $internalDate): void
    {
        $client = $this->connect($target);

        try {
            $folder = $client->getFolder($target->folder);
            if ($folder === null) {
                throw new RuntimeException(
                    "Cartella IMAP '{$target->folder}' non trovata su {$target->email}.",
                );
            }

            // INTERNALDATE = now() (deciso dal chiamante) così il messaggio cade
            // nella finestra date_window_days del connettore anche se la fixture
            // è datata nel passato.
            $folder->appendMessage($rawRfc822, null, $internalDate);
        } finally {
            $client->disconnect();
        }
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

            $messages = $folder->query()
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

    private function connect(MailboxTarget $target): \Webklex\PHPIMAP\Client
    {
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
    }
}
