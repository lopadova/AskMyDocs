<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Capabilities\AgentCapabilitySnapshotBuilder;
use App\Agent\Tools\AgentToolRegistry;
use App\Agent\Waves\AgentWaveCoordinator;
use App\Models\AgentRun;

/** Executes the authorized research graph and returns evidence for the original turn. */
final readonly class AgentContextGenerator
{
    public function __construct(
        private AgentWaveCoordinator $waves,
        private AgentToolRegistry $tools,
        private AgentCapabilitySnapshotBuilder $capabilities,
    ) {}

    public function generate(AgentRun $run, AgentExecutionContext $context, ?string $turnContext): AgentLoopOutcome
    {
        $context->assertMatches($run);
        $tools = $this->tools->forResearch($run, $context);

        return $this->waves->run($run, $context, $turnContext, $tools, $this->capabilities->build($tools));
    }
}
