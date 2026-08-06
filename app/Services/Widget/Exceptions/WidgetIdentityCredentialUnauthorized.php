<?php

declare(strict_types=1);

namespace App\Services\Widget\Exceptions;

use RuntimeException;

final class WidgetIdentityCredentialUnauthorized extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You are not authorized to manage widget identity credentials.');
    }
}
