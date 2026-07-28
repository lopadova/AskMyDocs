<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final readonly class EmailDatasetEnvironmentGuard
{
    public function __construct(private Application $application) {}

    public function assertRemoteMutationAllowed(): void
    {
        if ($this->application->environment(['local', 'testing'])) {
            return;
        }

        throw new RuntimeException(
            'APPEND e purge dei dataset e-mail sono consentiti soltanto in APP_ENV=local o testing.',
        );
    }
}
