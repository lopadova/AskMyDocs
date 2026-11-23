<?php

declare(strict_types=1);

namespace App\Pact\Native;

use App\Models\AgentRun;
use PactLaravel\Models\Connection;
use PactLaravel\Models\Member;
use PactLaravel\Models\Task;
use PactLaravel\Runtime\TaskService;

final class TaskAccess
{
    public function bindGeneration(Task $task): void
    {
        $peer = request()->attributes->get('pact.connection');
        $connection = $peer instanceof Connection && $peer->getKey() === $task->connection_id
            ? $peer
            : Connection::query()->find($task->connection_id);
        $task->checkpoint = [
            ...($task->checkpoint ?? []),
            'askmydocs_connection_generation' => (int) ($connection?->metadata['generation'] ?? 0),
        ];
    }

    public function permits(Task $task): bool
    {
        $connection = Connection::query()->whereKey($task->connection_id)
            ->where('user_id', $task->user_id)->where('federation_id', $task->federation_id)
            ->where('status', 'active')->first();

        return $connection !== null
            && (int) ($task->checkpoint['askmydocs_connection_generation'] ?? 0) === (int) ($connection->metadata['generation'] ?? 0)
            && Member::query()->where('connection_id', $connection->id)
            ->where('federation_id', $task->federation_id)->where('member_id', $connection->member_id)
            ->where('active', true)->exists();
    }

    public function assertRun(AgentRun $run): void
    {
        $id = data_get($run->input_json, 'pact_client_task_id');
        if (! is_string($id)) {
            return;
        }
        $task = Task::query()->find($id);
        $subject = $task === null ? null : app(SubjectResolver::class)->find((string) $task->user_id);
        if (! config('pact.enabled') || config('pact.role') !== 'client' || $task?->state !== 'TASK_STATE_WORKING'
            || ! $subject instanceof Subject || $subject->tenant_id !== $run->tenant_id
            || $subject->project_key !== $run->project_key || (int) $subject->user_id !== (int) $run->user_id
            || ! $this->permits($task)) {
            throw new \DomainException('The PACT client authorization is no longer active.');
        }
    }

    public function reportProgress(AgentRun $run, string $stage): void
    {
        $taskId = data_get($run->input_json, 'pact_client_task_id');
        if (! is_string($taskId) || $run->isTerminal() || ! method_exists(TaskService::class, 'reportProgress')) {
            return;
        }

        try {
            $this->assertRun($run);
        } catch (\DomainException) {
            return;
        }

        rescue(fn () => app(TaskService::class)->reportProgress($taskId, $stage));
    }
}
