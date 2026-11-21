<?php

declare(strict_types=1);

namespace App\Pact\Native;

use App\Models\AgentRun;
use PactLaravel\Models\Connection;
use PactLaravel\Models\Member;
use PactLaravel\Models\Task;

final class TaskAccess
{
    public function permits(Task $task): bool
    {
        $connection = Connection::query()->whereKey($task->connection_id)
            ->where('user_id', $task->user_id)->where('federation_id', $task->federation_id)
            ->where('status', 'active')->first();

        return $connection !== null && Member::query()->where('connection_id', $connection->id)
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
}
