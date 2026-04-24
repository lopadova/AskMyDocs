<?php

declare(strict_types=1);

namespace App\Services\Admin;

use RuntimeException;

/**
 * Thrown by CommandRunnerService on any schema / confirm-token
 * validation failure. Maps to HTTP 422.
 *
 * Carries a structured `errors` array the controller returns
 * verbatim so the React wizard can render field-specific hints
 * without re-parsing the message string.
 */
class CommandRunnerValidation extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
