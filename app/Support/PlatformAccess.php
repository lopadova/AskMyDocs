<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical names for the two distinct administrative boundaries.
 *
 * `super-admin` is the highest tenant role. It does not imply access to a
 * tenant: the user still needs a project membership in that tenant.
 *
 * `system-admin` is the platform control-plane role. It is granted only by
 * the dedicated CLI workflow and is always paired with `super-admin` so the
 * operator can also use tenant administration surfaces after selecting a
 * tenant.
 */
final class PlatformAccess
{
    public const SYSTEM_ADMIN_ROLE = 'system-admin';

    public const TENANT_SUPER_ADMIN_ROLE = 'super-admin';

    public const PLATFORM_ADMIN_PERMISSION = 'platform.admin';

    public const CROSS_TENANT_PERMISSION = 'tenant.cross-access';

    private function __construct() {}
}
