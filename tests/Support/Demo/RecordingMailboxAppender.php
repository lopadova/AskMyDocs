<?php

declare(strict_types=1);

namespace Tests\Support\Demo;

use App\Services\Demo\Contracts\MailboxAppender;
use App\Services\Demo\EmailSeedLockLease;
use App\Services\Demo\MailboxAppendResult;
use App\Services\Demo\MailboxTarget;
use App\Services\Demo\PreparedEmailMessage;
use Closure;
use DateTimeInterface;
use RuntimeException;

/**
 * Fake offline {@see MailboxAppender} per i test del comando `mail:seed-imap`:
 * registra ogni APPEND/purge invece di toccare un server IMAP reale (l'IMAP è il
 * solo confine esterno — R13/R26). Permette di provare sia che gli APPEND
 * avvengano (happy path) sia che NON avvengano (dry-run), e di osservare
 * l'ORDINE relativo purge↔append (timeline condivisa $events).
 *
 * @phpstan-type AppendRecord array{
 *     target: MailboxTarget,
 *     raw: string,
 *     internalDate: DateTimeInterface,
 *     sequence: int,
 *     verifyBeforeAppend: bool
 * }
 */
final class RecordingMailboxAppender implements MailboxAppender
{
    /** @var list<array{target: MailboxTarget, raw: string, internalDate: DateTimeInterface, sequence: int, verifyBeforeAppend: bool}> */
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

    /**
     * @param  list<int>  $alreadyPresentSequences
     * @param  Closure(PreparedEmailMessage): void|null  $afterMessageStored
     * @param  Closure(): void|null  $afterPurge
     */
    public function __construct(
        private readonly int $purgeReturns = 0,
        private readonly array $alreadyPresentSequences = [],
        private readonly ?int $failAfterStored = null,
        private readonly ?Closure $afterMessageStored = null,
        private readonly ?Closure $afterPurge = null,
    ) {}

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
        $appended = 0;
        $alreadyPresent = 0;
        $processed = 0;

        foreach ($messages as $message) {
            if (! $message instanceof PreparedEmailMessage) {
                throw new \InvalidArgumentException('Expected PreparedEmailMessage.');
            }

            $lease?->refresh();
            $exists = $message->verifyBeforeAppend
                && in_array($message->sequence, $this->alreadyPresentSequences, true);
            if ($exists) {
                $alreadyPresent++;
            } else {
                $this->appends[] = [
                    'target' => $target,
                    'raw' => $message->raw,
                    'internalDate' => $message->internalDate,
                    'sequence' => $message->sequence,
                    'verifyBeforeAppend' => $message->verifyBeforeAppend,
                ];
                $appended++;
            }

            if ($this->afterMessageStored !== null) {
                ($this->afterMessageStored)($message);
            }

            // Mirrors the real appender: an ambiguous remote ACK after lease
            // loss must never reach the checkpoint callback.
            $lease?->refresh();
            if ($onStored !== null) {
                $onStored($message, $exists);
            }

            $processed++;
            if ($this->failAfterStored !== null && $processed >= $this->failAfterStored) {
                throw new RuntimeException('Injected APPEND interruption.');
            }
        }
        $this->events[] = ['op' => 'append', 'mailbox' => $target->mailboxKey, 'count' => $appended];

        return new MailboxAppendResult($appended, $alreadyPresent);
    }

    public function purgeSeeded(
        MailboxTarget $target,
        string $headerName,
        string $value,
        ?EmailSeedLockLease $lease = null,
        ?Closure $onPurged = null,
    ): int {
        $operation = function () use (
            $target,
            $headerName,
            $value,
            $lease,
            $onPurged,
        ): int {
            $lease?->refresh();
            $this->purges[] = ['target' => $target, 'header' => $headerName, 'value' => $value];
            $this->events[] = ['op' => 'purge', 'mailbox' => $target->mailboxKey];
            if ($onPurged !== null && $this->purgeReturns > 0) {
                $onPurged($this->purgeReturns);
            }
            if ($this->afterPurge !== null) {
                ($this->afterPurge)();
            }
            $lease?->refresh();

            return $this->purgeReturns;
        };

        return $lease === null ? $operation() : $lease->runGuarded($operation);
    }

    /**
     * @return list<string>  mailbox_key di ogni APPEND registrato
     */
    public function appendedMailboxKeys(): array
    {
        return array_map(static fn (array $r): string => $r['target']->mailboxKey, $this->appends);
    }
}
