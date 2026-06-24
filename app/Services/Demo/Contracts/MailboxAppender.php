<?php

declare(strict_types=1);

namespace App\Services\Demo\Contracts;

use App\Services\Demo\MailboxTarget;
use DateTimeInterface;

/**
 * Seam sopra l'I/O IMAP usato da {@see \App\Services\Demo\ImapMailboxSeeder}.
 *
 * In produzione/locale è {@see \App\Services\Demo\WebklexMailboxAppender}
 * (webklex/php-imap); nei test si binda un fake che registra le chiamate, così
 * il comando `mail:seed-imap` è verificabile senza un server IMAP reale.
 */
interface MailboxAppender
{
    /**
     * Inserisce un messaggio RFC822 grezzo nella cartella target via IMAP APPEND.
     * Deve sollevare un'eccezione su fallimento (mai fallire in silenzio — R14/R4).
     */
    public function append(MailboxTarget $target, string $rawRfc822, DateTimeInterface $internalDate): void;

    /**
     * Elimina i messaggi della cartella target che portano l'header di seeding
     * uguale a $value. Ritorna il numero di messaggi rimossi.
     */
    public function purgeSeeded(MailboxTarget $target, string $headerName, string $value): int;
}
