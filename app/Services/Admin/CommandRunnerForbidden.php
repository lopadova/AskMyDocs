<?php

declare(strict_types=1);

namespace App\Services\Admin;

use RuntimeException;

/**
 * Thrown by CommandRunnerService when the caller lacks the Spatie
 * permission declared by the whitelisted command. Maps to HTTP 403.
 *
 * Separate class from CommandRunnerValidation so the controller can
 * distinguish permission-denied (403) from schema-invalid (422)
 * without string-matching message bodies.
 */
class CommandRunnerForbidden extends RuntimeException
{
}
