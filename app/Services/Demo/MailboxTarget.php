<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Coordinate IMAP risolte di una singola casella di test (ogni azienda ne ha 2:
 * `<project>-1` / `<project>-2`).
 *
 * Immutabile e privo di I/O: lo costruisce {@see ImapMailboxSeeder} da
 * {@see \Database\Seeders\TestEmailFixtures} + le env, e lo consuma un
 * {@see Contracts\MailboxAppender}. Tenere il `secret` qui (e non in env letta
 * dentro l'appender) rende l'appender testabile e privo di dipendenze globali.
 */
final readonly class MailboxTarget
{
    public function __construct(
        public string $mailboxKey,
        public string $projectKey,
        public string $companyName,
        public string $email,
        public string $host,
        public int $port,
        public string $encryption,
        public bool $validateCert,
        public string $secret,
        public string $folder,
    ) {}
}
