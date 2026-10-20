<?php

declare(strict_types=1);

namespace App\Actions;

use DateTimeImmutable;

final readonly class PendingActionApproval
{
    public function __construct(
        public string $approvalId,
        public string $executionId,
        public string $plainTextToken,
        public string $actionDigest,
        public ?string $targetDigest,
        public DateTimeImmutable $expiresAt,
    ) {}
}
