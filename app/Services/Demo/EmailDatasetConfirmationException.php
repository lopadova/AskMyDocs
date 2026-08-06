<?php

declare(strict_types=1);

namespace App\Services\Demo;

use RuntimeException;

final class EmailDatasetConfirmationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
