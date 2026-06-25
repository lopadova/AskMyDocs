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
 *
 * L'APPEND è a BATCH (una sola connessione per casella per N messaggi): con 100+
 * e-mail aprire una connessione per messaggio sarebbe lento e rischierebbe il
 * throttling lato Gmail.
 */
interface MailboxAppender
{
    /**
     * Inserisce N messaggi RFC822 grezzi nella cartella target via IMAP APPEND,
     * riusando UNA sola connessione. Deve sollevare un'eccezione su fallimento
     * (mai fallire in silenzio — R14/R4). Ritorna il numero di messaggi inseriti.
     *
     * @param  list<string>  $rfc822Messages
     */
    public function appendBatch(MailboxTarget $target, array $rfc822Messages, DateTimeInterface $internalDate): int;

    /**
     * Elimina i messaggi della cartella target che portano l'header di seeding
     * uguale a $value. Ritorna il numero di messaggi rimossi.
     */
    public function purgeSeeded(MailboxTarget $target, string $headerName, string $value): int;
}
