<?php

declare(strict_types=1);

namespace App\Services\Dev;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Controls only the two PID-owned local fixture processes. */
class LocalIntegrationFixtureLifecycle
{
    public function start(): void
    {
        $this->run('start');
    }

    public function stop(): void
    {
        $this->run('stop');
    }

    public function restart(): void
    {
        $this->run('restart');
    }

    private function run(string $action): void
    {
        $script = base_path('dev/local-integrations/local-integrations.sh');
        if (! is_file($script) || ! is_executable($script)) {
            throw new RuntimeException('The local integration fixture launcher is missing or not executable.');
        }

        $process = new Process([$script, $action], base_path());
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                trim($process->getErrorOutput())
                    ?: trim($process->getOutput())
                    ?: "Unable to {$action} the local fixture servers.",
            );
        }
    }
}
