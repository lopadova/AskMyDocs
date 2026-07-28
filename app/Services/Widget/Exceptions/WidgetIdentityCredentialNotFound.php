<?php

declare(strict_types=1);

namespace App\Services\Widget\Exceptions;

use RuntimeException;

final class WidgetIdentityCredentialNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Widget key not found.');
    }
}
