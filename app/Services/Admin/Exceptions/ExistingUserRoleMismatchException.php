<?php

declare(strict_types=1);

namespace App\Services\Admin\Exceptions;

use RuntimeException;

final class ExistingUserRoleMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $requestedRole,
        public readonly ?string $effectiveRole,
    ) {
        parent::__construct(
            $effectiveRole === null
                ? "The existing account has no tenant role and cannot satisfy requested role '{$requestedRole}'."
                : "The existing account role '{$effectiveRole}' is lower than requested role '{$requestedRole}'.",
        );
    }
}
