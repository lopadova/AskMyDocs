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
 * avvengano (happy path) sia che NON avvengano (dry-run).
 *
 * @phpstan-type AppendRecord array{target: MailboxTarget, raw: string, internalDate: DateTimeInterface}
 */
final class RecordingMailboxAppender implements MailboxAppender
{
    /** @var list<array{target: MailboxTarget, raw: string, internalDate: DateTimeInterface}> */
    public array $appends = [];

    /** @var list<array{target: MailboxTarget, header: string, value: string}> */
    public array $purges = [];

    public function __construct(private readonly int $purgeReturns = 0) {}

    public function append(MailboxTarget $target, string $rawRfc822, DateTimeInterface $internalDate): void
    {
        $this->appends[] = ['target' => $target, 'raw' => $rawRfc822, 'internalDate' => $internalDate];
    }

    public function purgeSeeded(MailboxTarget $target, string $headerName, string $value): int
    {
        $this->purges[] = ['target' => $target, 'header' => $headerName, 'value' => $value];

        return $this->purgeReturns;
    }

    /**
     * @return list<string>  mailbox_key di ogni APPEND registrato
     */
    public function appendedMailboxKeys(): array
    {
        return array_map(static fn (array $r): string => $r['target']->mailboxKey, $this->appends);
    }

    /**
     * @return list<string>  project_key di ogni APPEND registrato
     */
    public function appendedProjectKeys(): array
    {
        return array_map(static fn (array $r): string => $r['target']->projectKey, $this->appends);
    }
}
