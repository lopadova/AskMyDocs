<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

use App\Models\InviteCampaign;
use DateTimeInterface;

/**
 * Raw request-boundary signals handed to the FraudDetector. PII (ip / email /
 * fingerprint) arrives raw here and is hashed/canonicalized inside the
 * detector before anything is persisted (docs/10-anti-abuse.md). `now` is
 * injectable so the velocity windows are deterministic under test.
 */
final class AssessmentContext
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $action,             // 'redeem' | 'reward_grant'
        public readonly ?int $accountId = null,
        public readonly ?string $ip = null,
        public readonly ?string $fingerprint = null,
        public readonly ?string $email = null,
        public readonly ?InviteCampaign $campaign = null,
        public readonly bool $honeypot = false,
        public readonly ?int $codeId = null,
        public readonly ?DateTimeInterface $now = null,
    ) {
    }
}
