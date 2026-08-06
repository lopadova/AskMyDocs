<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

final class CompanyOnboardingNotRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Company onboarding is not available for this account.');
    }
}
