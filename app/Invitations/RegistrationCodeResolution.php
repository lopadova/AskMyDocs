<?php

declare(strict_types=1);

namespace App\Invitations;

use Padosoft\Invitations\Models\InviteCode;
use Padosoft\Invitations\Support\RedemptionError;

/**
 * Result of resolving a public registration code without exposing tenant
 * discovery details to the caller.
 */
final readonly class RegistrationCodeResolution
{
    public const COMPANY_BOOTSTRAP = 'company_bootstrap';

    public const TENANT_JOIN = 'tenant_join';

    private function __construct(
        public bool $ok,
        public ?RedemptionError $error = null,
        public ?InviteCode $code = null,
        public ?string $redemptionTenant = null,
        public ?string $intent = null,
        public ?string $targetTenant = null,
    ) {}

    public static function invalid(?RedemptionError $error = null): self
    {
        return new self(false, $error ?? RedemptionError::Invalid);
    }

    public static function valid(
        InviteCode $code,
        string $redemptionTenant,
        string $intent,
        ?string $targetTenant,
    ): self {
        return new self(
            true,
            code: $code,
            redemptionTenant: $redemptionTenant,
            intent: $intent,
            targetTenant: $targetTenant,
        );
    }
}
