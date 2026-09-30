<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Services\Chat\QuestionUnderstanding;
use Closure;

/** Public task progress contains questions and states, never model reasoning or source payloads. */
final class AgentResearchProgress
{
    public function observer(AgentRun $run): Closure
    {
        // Scalars only: this callback also travels into isolated KB research processes.
        $id = $run->id;
        $tenant = $run->tenant_id;

        return static function (string $type, array $data) use ($id, $tenant): void {
            $current = AgentRun::query()->forTenant($tenant)->findOrFail($id);
            app(AgentEventPublisher::class)->publish($current, $type, data: $data);
        };
    }

    public function planned(AgentRun $run, QuestionUnderstanding $understanding): void
    {
        if ($run->events()->where('type', 'research.planned')->exists()) {
            return; // A resume retains the original task list and its progress.
        }
        $tasks = [];
        foreach ($understanding->subquestions as $index => $sub) {
            $tasks[] = ['id' => $index, 'question' => $understanding->forSubquestion($index)->intent];
        }
        ($this->observer($run))('research.planned', ['tasks' => $tasks]);
    }

    public function branch(AgentRun $run, string $status): void
    {
        app(AgentEventPublisher::class)->publish($run, 'research.task', data: ['task_status' => $status]);
    }

    public function finished(AgentRun $run, AgentAnswer $answer): void
    {
        $tasks = [];
        foreach (data_get($run->result_json, 'question_understanding.subquestions', []) as $index => $sub) {
            $coverage = $answer->grounding['subquestions'][$index]['status'] ?? 'unverified';
            $status = match ($coverage) {
                'answered' => 'answered',
                'conflicting' => 'conflicting',
                default => 'unverified',
            };
            $flowStatus = data_get($run->result_json, 'research_flows.'.$index.'.status', 'completed');
            if ($status === 'unverified' && in_array($flowStatus, ['failed', 'cancelled'], true)) {
                $status = $flowStatus;
            }
            $draft = data_get($run->result_json, 'research_drafts.'.$index.'.payload.completeness');
            $checks = array_filter(data_get($answer->grounding, 'semantic_validation.0.checks', []),
                fn ($check) => ($check['subquestion_id'] ?? null) === $index);
            if ($status === 'answered' && (($draft !== null && $draft !== 'complete')
                || $flowStatus !== 'completed'
                || array_filter($checks, fn ($check) => ($check['fallback'] ?? null) !== null
                    || (($check['status'] ?? '') !== 'accepted' && ! in_array($check['reason'] ?? '', ['already_communicated', 'duplicate_fact'], true))))) {
                $status = 'partial';
            }
            $tasks[] = ['id' => $index, 'task_status' => $status];
        }
        if (count($tasks) > 1) {
            ($this->observer($run))('research.finished', ['tasks' => $tasks]);
        }
    }
}
