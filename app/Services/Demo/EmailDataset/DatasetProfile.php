<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class DatasetProfile
{
    public function __construct(
        public string $key,
        public ?int $mailboxTarget,
        public bool $includeGold,
        public float $threadRatio,
        public DateTimeImmutable $timelineStart,
        public DateTimeImmutable $timelineEnd,
    ) {
        if (! preg_match('/^[a-z0-9-]+$/', $this->key)) {
            throw new InvalidArgumentException("Invalid dataset profile key: {$this->key}");
        }

        if ($this->mailboxTarget !== null && $this->mailboxTarget < 1) {
            throw new InvalidArgumentException('mailbox_target must be null or a positive integer.');
        }

        if ($this->threadRatio < 0 || $this->threadRatio > 1) {
            throw new InvalidArgumentException('thread_ratio must be between 0 and 1.');
        }

        if ($this->timelineEnd < $this->timelineStart) {
            throw new InvalidArgumentException('timeline_end must not precede timeline_start.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $utc = new DateTimeZone('UTC');
        $startValue = (string) ($data['timeline_start'] ?? '');
        $endValue = (string) ($data['timeline_end'] ?? '');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $utc);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $utc);

        if (
            $start === false
            || $end === false
            || $start->format('Y-m-d') !== $startValue
            || $end->format('Y-m-d') !== $endValue
        ) {
            throw new InvalidArgumentException('Dataset profile timeline must use YYYY-MM-DD dates.');
        }

        $target = $data['mailbox_target'] ?? null;

        return new self(
            key: (string) ($data['key'] ?? ''),
            mailboxTarget: $target === null ? null : (int) $target,
            includeGold: (bool) ($data['include_gold'] ?? false),
            threadRatio: (float) ($data['thread_ratio'] ?? 0),
            timelineStart: $start,
            timelineEnd: $end,
        );
    }

    public function targetForMailbox(int $goldCount): int
    {
        if ($this->mailboxTarget === null) {
            return $goldCount;
        }

        if ($this->includeGold && $goldCount > $this->mailboxTarget) {
            throw new InvalidArgumentException(
                "Profile {$this->key} targets {$this->mailboxTarget} records, below the {$goldCount} gold records."
            );
        }

        return $this->mailboxTarget;
    }
}
