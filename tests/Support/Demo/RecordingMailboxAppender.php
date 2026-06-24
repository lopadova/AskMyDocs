<?php

declare(strict_types=1);

namespace Tests\Support\Demo;

use App\Services\Demo\Contracts\MailboxAppender;
use App\Services\Demo\MailboxTarget;
use DateTimeInterface;

/**
 * Fake offline {@see MailboxAppender} per i test del comando `mail:seed-imap`:
 * registra ogni APPEND/purge invece di toccare un server IMAP reale (l'IMAP è il
 * solo confine esterno — R13/R26). Permette di provare sia che gli APPEND
 * avvengano (happy path) sia che NON avvengano (dry-run), e di osservare
 * l'ORDINE relativo purge↔append (timeline condivisa $events).
 *
 * @phpstan-type AppendRecord array{target: MailboxTarget, raw: string, internalDate: DateTimeInterface}
 */
final class RecordingMailboxAppender implements MailboxAppender
{
    /** @var list<array{target: MailboxTarget, raw: string, internalDate: DateTimeInterface}> */
    public array $appends = [];

    /** @var list<array{target: MailboxTarget, header: string, value: string}> */
    public array $purges = [];

    /**
     * Timeline condivisa di tutte le operazioni IMAP, in ordine: serve a provare
     * che il purge avvenga PRIMA dell'append (un append-then-purge cancellerebbe
     * i messaggi appena iniettati).
     *
     * @var list<array{op: 'purge'|'append', mailbox: string, count?: int}>
     */
    public array $events = [];

    public function __construct(private readonly int $purgeReturns = 0) {}

    public function appendBatch(MailboxTarget $target, array $rfc822Messages, DateTimeInterface $internalDate): int
    {
        foreach ($rfc822Messages as $raw) {
            $this->appends[] = ['target' => $target, 'raw' => $raw, 'internalDate' => $internalDate];
        }
        $this->events[] = ['op' => 'append', 'mailbox' => $target->mailboxKey, 'count' => count($rfc822Messages)];

        return count($rfc822Messages);
    }

    public function purgeSeeded(MailboxTarget $target, string $headerName, string $value): int
    {
        $this->purges[] = ['target' => $target, 'header' => $headerName, 'value' => $value];
        $this->events[] = ['op' => 'purge', 'mailbox' => $target->mailboxKey];

        return $this->purgeReturns;
    }

    /**
     * @return list<string>  mailbox_key di ogni APPEND registrato
     */
    public function appendedMailboxKeys(): array
    {
        return array_map(static fn (array $r): string => $r['target']->mailboxKey, $this->appends);
    }
}
