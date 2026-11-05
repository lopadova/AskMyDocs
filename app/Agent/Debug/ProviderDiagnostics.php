<?php

declare(strict_types=1);

namespace App\Agent\Debug;

use App\Ai\AiResponse;
use App\Decisions\DecisionResult;
use App\Models\AgentRun;
use App\Support\TenantContext;
use Throwable;

/** Optional technical tracing; it must never change a provider call or its exception. */
final class ProviderDiagnostics
{
    public function resolve(?string $runId, ?string $tenantId = null): ?AgentRun
    {
        if ($runId === null) {
            return null;
        }
        try {
            return AgentRun::query()->forTenant($tenantId ?? app(TenantContext::class)->current())
                ->where('run_id', $runId)->first();
        } catch (Throwable) {
            return null;
        }
    }

    public function measure(?AgentRun $run, string $phase, callable $call, array $metadata = [], ?string $parentSpanId = null): mixed
    {
        if ($run === null) {
            return $call();
        }
        $span = $this->begin($run, $phase, $metadata, $parentSpanId);
        try {
            $result = $call();
        } catch (Throwable $exception) {
            $this->failure($run, $span, $exception, $metadata);
            throw $exception;
        }
        if ($result instanceof AiResponse) {
            $metadata = [...$metadata, 'provider' => $result->provider, 'model' => $result->model];
        } elseif ($result instanceof DecisionResult) {
            $metadata = [...$metadata, 'model' => $result->model, 'result_count' => count($result->answers)];
        }
        $this->end($run, $span, $metadata);
        return $result;
    }

    public function end(?AgentRun $run, ?string $span, array $metadata = []): void
    {
        if ($run === null) {
            return;
        }
        try {
            app(RunDiagnostics::class)->end($run, $span, 'completed', $metadata);
        } catch (Throwable) {
        }
    }

    public function failure(?AgentRun $run, ?string $span, Throwable $exception, array $metadata = []): void
    {
        if ($run === null) {
            return;
        }
        try {
            app(RunDiagnostics::class)->failure($run, $span, $exception, $metadata);
        } catch (Throwable) {
        }
    }

    public function decisionMetadata(string $operation): array
    {
        $name = (string) config('decisions.default', 'jev');
        return array_filter([
            'operation' => $operation, 'wait_for' => 'provider_response',
            'model' => config("decisions.models.{$name}.model"),
            'timeout_seconds' => config("decisions.models.{$name}.timeout"),
            'error_handled' => true,
        ], static fn ($value) => $value !== null);
    }

    public function begin(?AgentRun $run, string $phase, array $metadata = [], ?string $parentSpanId = null): ?string
    {
        if ($run === null) {
            return null;
        }
        try {
            return app(RunDiagnostics::class)->begin($run, $phase, $metadata, $parentSpanId);
        } catch (Throwable) {
            return null;
        }
    }
}
