<?php

declare(strict_types=1);

namespace App\Services\Widget\Exceptions;

use RuntimeException;

final class WidgetIdentityCredentialDisabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Enable authenticated users for this widget first.');
    }
}
