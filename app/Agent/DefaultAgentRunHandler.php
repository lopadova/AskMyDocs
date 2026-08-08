<?php

declare(strict_types=1);

namespace App\Agent;

use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use Throwable;

/** Queue entry point that owns run lifecycle, collection and final synthesis. */
final readonly class DefaultAgentRunHandler implements AgentRunHandler
{
    public function __construct(
        private AgentLoop $loop,
        private AgentAnswerSynthesizer $synthesizer,
        private AgentEventPublisher $events,
    ) {}

    public function handle(AgentRun $run): void
    {
        $run->refresh();
        if ($run->isTerminal() || $run->status === AgentRun::STATUS_AWAITING_CONFIRMATION) {
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
            $outcome = $this->loop->run($run, $context);
            if ($outcome->awaitingConfirmation()) {
                return;
            }

            $this->events->publish($run, 'synthesis.started', 'synthesis.started');
            $answer = $this->synthesizer->synthesize(
                trim((string) data_get($run->input_json, 'question', '')),
                $context,
                $outcome,
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
