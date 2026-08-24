<?php

declare(strict_types=1);

namespace App\Services\Demo;

use LogicException;

/**
 * Durable contiguous progress for one mailbox and one immutable dataset.
 */
final readonly class EmailSeedCheckpoint
{
    public function __construct(
        public string $mailboxKey,
        public string $datasetVersion,
        public string $manifestChecksum,
        public int $lastSequence = 0,
        public int $appended = 0,
        public int $alreadyPresent = 0,
    ) {}

    public function advance(PreparedEmailMessage $message, bool $alreadyPresent): self
    {
        if ($message->sequence !== $this->lastSequence + 1) {
            throw new LogicException(sprintf(
                'Checkpoint non contiguo per %s: atteso %d, ricevuto %d.',
                $this->mailboxKey,
                $this->lastSequence + 1,
                $message->sequence,
            ));
        }

        return new self(
            mailboxKey: $this->mailboxKey,
            datasetVersion: $this->datasetVersion,
            manifestChecksum: $this->manifestChecksum,
            lastSequence: $message->sequence,
            appended: $this->appended + ($alreadyPresent ? 0 : 1),
            alreadyPresent: $this->alreadyPresent + ($alreadyPresent ? 1 : 0),
        );
    }
}
