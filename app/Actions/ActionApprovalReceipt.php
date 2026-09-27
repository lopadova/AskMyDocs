<?php

declare(strict_types=1);

namespace App\Actions;

use DateTimeImmutable;

final readonly class ActionApprovalReceipt
{
    public function __construct(
        public string $approvalId,
        public string $executionId,
        public string $tokenHash,
        public string $actionDigest,
        public ?string $targetDigest,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $approvedAt,
    ) {}
}
