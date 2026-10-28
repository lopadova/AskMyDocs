<?php

declare(strict_types=1);

namespace App\Agent\Debug;

use App\Agent\AgentRunCancelledException;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use App\Support\SensitivePayloadRedactor;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Content-free, bounded, best-effort trace. Never participates in run decisions. */
final class RunDiagnostics
{
    public const MAX_SPANS = 256;
    public const MAX_METADATA_BYTES = 2048;

    private ?bool $available = null;

    public function __construct(private readonly SensitivePayloadRedactor $redactor) {}

    public function available(): bool
    {
        try {
            return $this->available ??= Schema::hasTable('agent_diagnostic_spans');
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $metadata */
    public function begin(AgentRun $run, string $phase, array $metadata = [], ?string $parentSpanId = null): ?string
    {
        try {
            // Only a completed catch can establish that an error was handled.
            unset($metadata['error_handled']);
            if (! $this->available() || preg_match('/^[a-z][a-z0-9_.]{0,79}$/D', $phase) !== 1) {
                return null;
            }

            return DB::transaction(function () use ($run, $phase, $metadata, $parentSpanId): ?string {
                // This lock makes the per-run cap exact even with parallel observers.
                $owner = AgentRun::query()->forTenant($run->tenant_id)->whereKey($run->id)->lockForUpdate()->first();
                if ($owner === null || $this->spans($run)->count() >= self::MAX_SPANS) {
                    return null;
                }
                // Reconnecting browsers must not crowd out the execution trace.
                if (str_starts_with($phase, 'transport.') && $this->spans($run)->where('phase', 'like', 'transport.%')->count() >= 8) {
                    return null;
                }
                if ($parentSpanId !== null && ! $this->spans($run)->whereKey($parentSpanId)->exists()) {
                    $parentSpanId = null;
                }
                $id = (string) Str::uuid();
                AgentDiagnosticSpan::create([
                    'id' => $id, 'tenant_id' => $run->tenant_id, 'agent_run_id' => $run->id,
                    'parent_span_id' => $parentSpanId, 'phase' => $phase, 'status' => 'started',
                    'metadata_json' => $this->metadata($metadata), 'started_at' => now(),
                ]);

                return $id;
            });
        } catch (Throwable) {
            // Logging cannot turn a successful search into a failure. A missing
            // migration/DB failure remains an explicit coverage gap in the export.
            return null;
        }
    }

    /** @param array<string,mixed> $metadata */
    public function end(AgentRun $run, ?string $spanId, string $status = 'completed', array $metadata = []): void
    {
        if ($spanId === null || ! in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }
        try {
            // A diagnostic write error must roll back its savepoint rather than
            // poison a caller's surrounding PostgreSQL transaction.
            DB::transaction(function () use ($run, $spanId, $status, $metadata): void {
                $span = $this->spans($run)->whereKey($spanId)->where('status', 'started')->first();
                if ($span === null) {
                    return;
                }
                $endedAt = now();
                $mergedMetadata = [...($span->metadata_json ?? []), ...$metadata];
                if ($status === 'completed') {
                    unset($mergedMetadata['error_handled']);
                }
                $this->spans($run)->whereKey($spanId)->where('status', 'started')->update([
                    'status' => $status, 'ended_at' => $endedAt,
                    'duration_ms' => max(0, (int) $span->started_at->diffInMilliseconds($endedAt)),
                    'metadata_json' => json_encode($this->metadata($mergedMetadata), JSON_THROW_ON_ERROR),
                    'updated_at' => $endedAt,
                ]);
            });
        } catch (Throwable) {
            // The original result/exception always wins over diagnostics.
        }
    }

    public function failure(AgentRun $run, ?string $spanId, Throwable $exception, array $metadata = []): void
    {
        $this->end($run, $spanId, $exception instanceof AgentRunCancelledException ? 'cancelled' : 'failed', [
            ...$metadata, 'error_class' => $exception::class, 'error_kind' => $this->errorKind($exception),
        ]);
    }

    public function measure(AgentRun $run, string $phase, callable $callback, array $metadata = []): mixed
    {
        $span = $this->begin($run, $phase, $metadata);
        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $this->failure($run, $span, $exception, $metadata);
            throw $exception;
        }
        $resultMetadata = [];
        if ($result instanceof \App\Ai\AiResponse || $result instanceof \App\Ai\EmbeddingsResponse) {
            $resultMetadata = ['provider' => $result->provider, 'model' => $result->model];
        } elseif ($result instanceof \Illuminate\Support\Collection) {
            $resultMetadata = ['result_count' => $result->count()];
        }
        $status = 'completed';
        if ($result instanceof \App\Services\Chat\Reasoning\ResearchFailure) {
            $status = 'failed';
            $reason = explode(':', $result->reason, 2)[0];
            $resultMetadata = ['operation' => in_array($reason, ['research_branch_failed', 'research_process_unavailable', 'research_process_error'], true)
                ? $reason : 'research_failure', 'error_handled' => true];
        }
        $this->end($run, $span, $status, $resultMetadata);

        return $result;
    }

    /** Serializable across the existing isolated research processes; no models/services captured. */
    public function observer(AgentRun $run): Closure
    {
        $id = $run->id;
        $tenant = $run->tenant_id;

        return static function (string $phase, callable $callback, array $metadata = []) use ($id, $tenant): mixed {
            try {
                $current = AgentRun::query()->forTenant($tenant)->find($id);
                $diagnostics = app(self::class);
            } catch (Throwable) {
                $current = null;
            }

            return $current === null ? $callback() : $diagnostics->measure($current, $phase, $callback, $metadata);
        };
    }

    /** Report actual backend configuration, never probe network/queue or read arbitrary environment. */
    public function runtime(): array
    {
        $connection = (string) config('queue.default', 'sync');
        $values = [
            'environment' => app()->environment(), 'release' => config('agent.diagnostics.release'),
            'php_version' => PHP_VERSION, 'laravel_version' => app()->version(),
            'config_cached' => app()->configurationIsCached(),
            'queue_connection' => $connection, 'queue_driver' => config('queue.connections.'.$connection.'.driver', 'unknown'),
            'queue_name' => config('agent.queue', 'agent'),
            'queue_retry_after_seconds' => config('queue.connections.'.$connection.'.retry_after'),
            'job_timeout_seconds' => (int) config('agent.job_timeout_seconds', 120),
            'cache_driver' => config('cache.stores.'.config('cache.default').'.driver', 'unknown'),
            'database_driver' => config('database.connections.'.config('database.default').'.driver', 'unknown'),
            'planner_mode' => config('agent.planner.mode', 'classic'),
            'waves_enabled' => (bool) config('agent.waves.enabled', true),
            'wave_concurrency' => config('agent.waves.concurrency', 10),
            'wave_timeout_seconds' => config('agent.waves.timeout_seconds', 45),
            'fanout_driver' => config('agent.tools.fanout_driver'),
            'fanout_concurrency' => config('agent.tools.fanout_concurrency'),
            'fanout_timeout_seconds' => config('agent.tools.fanout_timeout_seconds'),
            'reasoning_enabled' => (bool) config('reasoning.enabled'),
            'research_driver' => app()->runningUnitTests() ? 'inline_test' : 'process',
            'research_concurrency' => config('reasoning.research_concurrency'),
            'research_timeout_seconds' => config('reasoning.research_timeout_seconds'),
            'pcntl_available' => extension_loaded('pcntl'), 'redis_extension_available' => extension_loaded('redis'),
            'worker_liveness' => 'not_probed',
        ];

        return $this->technicalValues($values);
    }

    /** Only these scalar technical fields can enter the trace; no arguments, output or exception messages. */
    private function metadata(array $metadata): array
    {
        $keys = ['tool_kind', 'tool_name', 'logical_index', 'iteration', 'operation', 'provider', 'model', 'result_count',
            'wait_reason', 'wait_for', 'child_run_id', 'research_flow_id', 'driver', 'concurrency', 'timeout_seconds',
            'error_class', 'error_kind', 'error_handled', 'attempt', 'job_id', 'queue_connection', 'queue_name',
            'queue_driver', 'retry_after_seconds', 'dispatch_recorded', 'lock_acquired', 'observed_status',
            'reason', 'remaining_seconds', 'required_seconds'];
        $keys[] = 'last_sequence';
        $safe = $this->technicalValues(array_intersect_key($metadata, array_flip($keys)));
        if (isset($metadata['runtime']) && is_array($metadata['runtime'])) {
            // Rebuild from OUR allowlist; never accept a caller's arbitrary runtime array.
            $safe['runtime'] = $this->runtime();
        }
        if (($metadata['_truncated'] ?? false) === true) {
            $safe['_truncated'] = true;
        }
        if (strlen(json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE)) > self::MAX_METADATA_BYTES) {
            unset($safe['runtime']);
            $priority = array_intersect_key($safe, array_flip(['error_class', 'error_kind', 'error_handled',
                'operation', 'wait_for', 'wait_reason', 'child_run_id', 'research_flow_id', 'logical_index', 'iteration']));
            $safe = [...$priority, ...$safe];
            while (strlen(json_encode([...$safe, '_truncated' => true], JSON_INVALID_UTF8_SUBSTITUTE)) > self::MAX_METADATA_BYTES) {
                array_pop($safe);
            }
            $safe['_truncated'] = true;
        }

        return $safe;
    }

    private function technicalValues(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                // Reject URLs with parameters/userinfo, free prose and serialized payloads.
                $value = preg_match('/^[a-zA-Z0-9_.:\/\\\\-]{1,160}$/D', $value) === 1 ? $value : '[omitted]';
                $safe[$key] = $value;
            } elseif (is_bool($value) || is_int($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (in_array($key, ['remaining_seconds', 'required_seconds'], true) && is_float($value) && is_finite($value)) {
                $safe[$key] = $value;
            }
        }

        return $this->redactor->redact($safe);
    }

    private function errorKind(Throwable $exception): string
    {
        if ($exception instanceof AgentRunCancelledException) {
            return 'cancelled';
        }
        // Class-based observation only: a quiet trace never proves a timeout.
        return preg_match('/timeout|timedout/i', $exception::class) === 1 ? 'timeout' : 'exception';
    }

    private function spans(AgentRun $run): \Illuminate\Database\Eloquent\Builder
    {
        return AgentDiagnosticSpan::query()->forTenant($run->tenant_id)->where('agent_run_id', $run->id);
    }
}
