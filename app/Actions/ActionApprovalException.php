<?php

declare(strict_types=1);

namespace App\Actions;

use RuntimeException;

final class ActionApprovalException extends RuntimeException
{
    public static function invalid(): self
    {
        return new self('Action approval is invalid, expired, already consumed, or no longer authorized.');
    }
}
