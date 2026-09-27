<?php

declare(strict_types=1);

namespace App\Services\Dev;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Starts only the two PID-owned local fixture processes. */
class LocalIntegrationFixtureLifecycle
{
    public function restart(): void
    {
        $script = base_path('dev/local-integrations/local-integrations.sh');
        if (! is_file($script) || ! is_executable($script)) {
            throw new RuntimeException('The local integration fixture launcher is missing or not executable.');
        }

        $process = new Process([$script, 'restart'], base_path());
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Unable to restart the local fixture servers.');
        }
    }
}
