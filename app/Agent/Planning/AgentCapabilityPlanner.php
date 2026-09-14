<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\AgentExecutionContext;
use App\Agent\Capabilities\AgentCapabilityRouter;
use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Tools\AgentToolDefinition;

final readonly class AgentCapabilityPlanner
{
    public function __construct(
        private AgentCapabilityRouter $router,
        private AgentPlanner $planner,
        private AgentPlanValidator $validator,
        private AgentPlanArgumentNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string,AgentToolDefinition>  $tools
     * @param  list<array<string,mixed>>  $completedActions
     * @param  array<string,array<string,mixed>>  $results
     */
    public function decide(
        string $question,
        AgentExecutionContext $context,
        array $tools,
        AgentCapabilitySnapshot $snapshot,
        AgentEvidenceEnvelope $evidence,
        array $completedActions,
        array $results,
        ?string $turnContext,
    ): AgentCapabilityPlanningResult {
        $route = $this->router->route($question, $turnContext, $snapshot, $evidence->documents() !== []);
        $candidateTools = array_intersect_key($tools, array_flip($route->candidateTools));
        $candidateCapabilities = array_intersect_key($snapshot->capabilities, $candidateTools);
        $encoded = json_encode(array_map(
            static fn ($capability): array => $capability->jsonSerialize(),
            $candidateCapabilities,
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $candidateSnapshot = new AgentCapabilitySnapshot(
            $candidateCapabilities,
            hash('sha256', $encoded),
            strlen($encoded),
        );

        $attempt = $this->planner->decideAttempt(
            $question,
            $context,
            $candidateTools,
            $evidence,
            $completedActions,
            $turnContext,
            $candidateSnapshot,
        );
        $attempt = $this->normalized($attempt, $candidateTools);
        $plannerLatency = $attempt->latencyMs;
        $promptTokens = $attempt->promptTokens;
        $completionTokens = $attempt->completionTokens;
        $corrections = 0;

        try {
            $this->validator->validate($attempt->plan, $candidateTools, $snapshot, $route, $completedActions, $results);
        } catch (AgentPlanValidationException $exception) {
            $corrections = 1;
            $attempt = $this->planner->decideAttempt(
                $question,
                $context,
                $candidateTools,
                $evidence,
                $completedActions,
                $turnContext,
                $candidateSnapshot,
                $exception->validationCode.': '.$exception->getMessage(),
            );
            $attempt = $this->normalized($attempt, $candidateTools);
            $plannerLatency += $attempt->latencyMs;
            $promptTokens = $this->sum($promptTokens, $attempt->promptTokens);
            $completionTokens = $this->sum($completionTokens, $attempt->completionTokens);
            $this->validator->validate($attempt->plan, $candidateTools, $snapshot, $route, $completedActions, $results);
        }

        return new AgentCapabilityPlanningResult(
            $attempt->plan,
            $route,
            $plannerLatency,
            $promptTokens,
            $completionTokens,
            $corrections,
        );
    }

    private function sum(?int $left, ?int $right): ?int
    {
        return $left === null && $right === null ? null : (int) $left + (int) $right;
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function normalized(AgentPlannerAttempt $attempt, array $tools): AgentPlannerAttempt
    {
        return new AgentPlannerAttempt(
            $this->normalizer->normalize($attempt->plan, $tools),
            $attempt->latencyMs,
            $attempt->promptTokens,
            $attempt->completionTokens,
        );
    }
}
