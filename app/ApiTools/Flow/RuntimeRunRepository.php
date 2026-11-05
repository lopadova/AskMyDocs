<?php

declare(strict_types=1);

namespace App\ApiTools\Flow;

use App\ApiTools\Models\ApiToolRun;
use App\ApiTools\ToolRuntime;
use Padosoft\LaravelFlow\Contracts\ConditionalRunRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Models\FlowRunRecord;

final readonly class RuntimeRunRepository implements RunRepository, ConditionalRunRepository
{
    public function __construct(private RunRepository $inner) {}
    public function create(array $attributes): FlowRunRecord { return $this->inner->create($attributes); }
    public function find(string $runId): ?FlowRunRecord { return $this->inner->find($runId); }
    public function findByIdempotencyKey(string $idempotencyKey): ?FlowRunRecord { return $this->inner->findByIdempotencyKey($idempotencyKey); }

    public function update(string $runId, array $attributes): FlowRunRecord
    {
        return $this->completed($this->inner->update($runId, $attributes));
    }

    public function updateWhereStatus(string $runId, string $expectedStatus, array $attributes): ?FlowRunRecord
    {
        if (! $this->inner instanceof ConditionalRunRepository) {
            throw new \LogicException('API tools require conditional Flow run persistence.');
        }
        $run = $this->inner->updateWhereStatus($runId, $expectedStatus, $attributes);
        return $run === null ? null : $this->completed($run);
    }

    private function completed(FlowRunRecord $flow): FlowRunRecord
    {
        if ($flow->definition_name !== 'askmydocs.api_tool') { return $flow; }
        $state = RunState::tryFrom((string) $flow->status);
        if ($state?->isTerminal()) {
            $run = ApiToolRun::query()->where('tenant_id', $flow->tenant_id)->where('flow_run_id', $flow->id)->first();
            if ($run !== null) {
                $status = match ($state) {
                    RunState::Succeeded => 'completed',
                    RunState::PartiallySucceeded => 'partial',
                    RunState::Aborted => 'cancelled',
                    default => 'failed',
                };
                app(ToolRuntime::class)->finish($run->id, $status, $status === 'failed' ? 'flow_failed' : null);
            }
        }
        return $flow;
    }
}
