<?php

declare(strict_types=1);

namespace App\Services\Admin;

use InvalidArgumentException;

/**
 * Thrown by CommandRunnerService when the requested command is not a
 * top-level key in `config('admin.allowed_commands')`.
 *
 * The controller maps this to HTTP 404 — NOT 422 — because "this
 * command does not exist" is indistinguishable from "this command
 * exists but you can't see it" to an unauthorised caller. 404 is the
 * right shape: it discloses nothing.
 */
class CommandRunnerUnknown extends InvalidArgumentException
{
    public static function forCommand(string $command): self
    {
        return new self("Command '{$command}' is not in the admin whitelist.");
    }
}
