<?php

declare(strict_types=1);

namespace App\Agent;

use App\Contracts\AgentRunHandler;
use App\Mcp\Apps\McpAppTurnContext;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use Throwable;

/** Queue entry point that owns run lifecycle, collection and final synthesis. */
final readonly class DefaultAgentRunHandler implements AgentRunHandler
{
    public function __construct(
        private AgentLoop $loop,
        private AgentAnswerSynthesizer $synthesizer,
        private AgentEventPublisher $events,
        private AgentResultProjector $projector,
        private McpAppTurnContext $mcpAppContext,
        private AgentTurnContextBuilder $turnContextBuilder,
    ) {}

    public function handle(AgentRun $run): void
    {
        $run->refresh();
        if ($run->isTerminal() || in_array($run->status, [
            AgentRun::STATUS_AWAITING_CONFIRMATION,
            AgentRun::STATUS_AWAITING_MCP_CONFIRMATION,
            AgentRun::STATUS_AWAITING_MCP_INPUT,
            AgentRun::STATUS_WAITING_MCP_TASK,
        ], true)) {
            return;
        }

        $context = $this->context($run);
        try {
            $context->assertMatches($run);
        } catch (\DomainException) {
            $run->forceFill([
                'status' => AgentRun::STATUS_FAILED,
                'error_code' => 'unauthorized',
                'completed_at' => now(),
            ])->save();
            $this->events->publish(
                $run,
                'run.failed',
                'run.failed',
                data: ['error_code' => 'unauthorized'],
                canCancel: false,
            );

            return;
        }
        $context->activate();
        $firstStart = ! $run->events()->where('type', 'run.started')->exists();
        $run->forceFill([
            'status' => AgentRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'error_code' => null,
        ])->save();
        if ($firstStart) {
            $this->events->publish($run, 'run.started', 'run.started');
        }

        try {
            $turnContext = $this->turnContext($run);
            $outcome = $this->loop->run($run, $context, $turnContext);
            if ($outcome->requiresInteraction()) {
                return;
            }

            $this->events->publish($run, 'synthesis.started', 'synthesis.started');
            $answer = $this->synthesizer->synthesize(
                trim((string) data_get($run->input_json, 'question', '')),
                $context,
                $outcome,
                $turnContext,
            );
            $status = $outcome->decision === 'partial' || $answer->completeness === 'partial'
                ? AgentRun::STATUS_PARTIAL
                : AgentRun::STATUS_COMPLETED;
            $checkpoint = is_array($run->result_json) ? $run->result_json : [];
            $run->forceFill([
                'status' => $status,
                'result_json' => array_merge($checkpoint, [
                    'phase' => 'final',
                    'decision' => $outcome->decision,
                    'stop_reason' => $outcome->stopReason,
                    'response' => $answer->jsonSerialize(),
                ]),
                'completed_at' => now(),
                'error_code' => null,
            ])->save();
            $this->projector->project($run, $answer);
            $this->events->publish(
                $run,
                $status === AgentRun::STATUS_PARTIAL ? 'run.partial' : 'run.completed',
                $status === AgentRun::STATUS_PARTIAL ? 'run.partial' : 'run.completed',
                data: ['response' => $answer->jsonSerialize()],
                canCancel: false,
            );
        } catch (AgentRunCancelledException) {
            // AgentRunControl already persisted and published the cancellation.
        } catch (Throwable $exception) {
            $run->refresh();
            if ($run->status === AgentRun::STATUS_CANCELLED) {
                return;
            }
            $run->forceFill([
                'status' => AgentRun::STATUS_FAILED,
                'error_code' => $this->errorCode($exception),
                'completed_at' => now(),
            ])->save();
            $this->events->publish(
                $run,
                'run.failed',
                'run.failed',
                data: ['error_code' => $run->error_code],
                canCancel: false,
            );
        }
    }

    private function turnContext(AgentRun $run): ?string
    {
        $appId = data_get($run->input_json, 'mcp_app_id');
        $user = $run->user;
        $conversation = $run->conversation;
        $mcpAppContext = null;

        if (is_string($appId) && $user instanceof User && $conversation instanceof Conversation) {
            $previousTimezone = date_default_timezone_get();
            try {
                // Connector timestamps are persisted in the application timezone.
                // Agent runs use the actor's timezone for localized output, so use
                // the storage timezone while authorizing expiry-bound app context.
                date_default_timezone_set((string) config('app.timezone', 'UTC'));

                $mcpAppContext = $this->mcpAppContext->resolve($appId, $user, $conversation);
            } finally {
                date_default_timezone_set($previousTimezone);
            }
        }

        $context = $this->turnContextBuilder->build($run, $mcpAppContext);

        return $context === []
            ? null
            : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function context(AgentRun $run): AgentExecutionContext
    {
        return AgentExecutionContext::fromArray([
            'run_id' => $run->run_id,
            'tenant_id' => $run->tenant_id,
            'project_key' => $run->project_key,
            'channel' => $run->channel,
            'actor_type' => $run->actor_type,
            'actor_id' => $run->actor_id,
            'locale' => $run->locale,
            'timezone' => $run->timezone,
        ]);
    }

    private function errorCode(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'budget') || str_contains($message, 'limit')) {
            return 'budget_exhausted';
        }
        if (str_contains($message, 'unauthor') || str_contains($message, 'scope')) {
            return 'unauthorized';
        }

        return 'agent_run_failed';
    }
}
