<?php

declare(strict_types=1);

namespace App\Services\Demo\Contracts;

use App\Services\Demo\MailboxAppendResult;
use App\Services\Demo\MailboxTarget;
use App\Services\Demo\PreparedEmailMessage;
use App\Services\Demo\EmailSeedLockLease;
use Closure;

/**
 * Seam sopra l'I/O IMAP usato da {@see \App\Services\Demo\ImapMailboxSeeder}.
 *
 * In produzione/locale è {@see \App\Services\Demo\WebklexMailboxAppender}
 * (webklex/php-imap); nei test si binda un fake che registra le chiamate, così
 * il comando `mail:seed-imap` è verificabile senza un server IMAP reale.
 *
 * L'APPEND riusa una sola connessione per casella, ma consuma un iterable:
 * anche dataset da decine di migliaia di messaggi restano memory-bounded.
 */
interface MailboxAppender
{
    /**
     * Inserisce i messaggi nella cartella target via IMAP APPEND, riusando una
     * sola connessione. Deve sollevare un'eccezione su fallimento (mai fallire
     * in silenzio — R14/R4).
     *
     * Il callback viene invocato solo dopo che il messaggio è stato confermato
     * presente: APPEND riuscito oppure Message-ID già trovato sul server.
     *
     * @param  iterable<PreparedEmailMessage>  $messages
     * @param  Closure(PreparedEmailMessage, bool): void|null  $onStored
     */
    public function appendStream(
        MailboxTarget $target,
        iterable $messages,
        ?Closure $onStored = null,
        ?EmailSeedLockLease $lease = null,
    ): MailboxAppendResult;

    /**
     * Elimina i messaggi della cartella target che portano l'header di seeding
     * uguale a $value. Ritorna il numero di messaggi rimossi.
     *
     * Il callback riceve il totale cumulativo solo dopo che il relativo batch
     * è stato espunto con successo dal server.
     *
     * @param  Closure(int): void|null  $onPurged
     */
    public function purgeSeeded(
        MailboxTarget $target,
        string $headerName,
        string $value,
        ?EmailSeedLockLease $lease = null,
        ?Closure $onPurged = null,
    ): int;
}
