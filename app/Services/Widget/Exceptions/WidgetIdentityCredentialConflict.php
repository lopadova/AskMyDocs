<?php

declare(strict_types=1);

namespace App\Services\Widget\Exceptions;

use RuntimeException;

final class WidgetIdentityCredentialConflict extends RuntimeException
{
    public function __construct(
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct('The identity credential changed while this request was in progress.');
    }
}
