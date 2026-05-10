<?php

declare(strict_types=1);

namespace App\Eval\Support;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Thin wrapper around `php artisan eval-harness:run` so the host can
 * mock-replace the call in feature tests without poking at the
 * Artisan facade (which Testbench wraps in a final Kernel class that
 * Mockery cannot mock).
 *
 * Production path: resolve through the container, defer to the
 * console kernel. Test path: bind a stub in the container that
 * pre-writes the report file the command would have produced.
 */
class EvalHarnessRunner
{
    public function __construct(private readonly ConsoleKernel $kernel) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function run(array $parameters): int
    {
        return $this->kernel->call('eval-harness:run', $parameters, new BufferedOutput);
    }
}
