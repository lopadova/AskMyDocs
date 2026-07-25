<?php

declare(strict_types=1);

namespace App\Services\Demo;

use DateTimeImmutable;

/**
 * A single, fully rendered IMAP APPEND unit.
 *
 * Keeping the per-message delivery metadata beside the RFC822 payload lets the
 * seeder stream arbitrarily large datasets without first materialising every
 * message in memory.
 */
final readonly class PreparedEmailMessage
{
    public function __construct(
        public string $raw,
        public DateTimeImmutable $internalDate,
        public string $fixtureId,
        public string $messageId,
        public int $sequence,
        public string $subject,
        public string $datasetVersion,
        public bool $verifyBeforeAppend = false,
    ) {}
}
