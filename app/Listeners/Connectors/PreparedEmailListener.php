<?php

declare(strict_types=1);

namespace App\Listeners\Connectors;

use App\Services\EmailTriage\EmailTriageService;

/** Deliberately synchronous: listener failure prevents source writes/checkpointing. */
final class PreparedEmailListener
{
    public function __construct(private readonly EmailTriageService $triage) {}

    public function handle(array $envelope): void
    {
        $this->triage->registerEnvelope($envelope);
    }
}
