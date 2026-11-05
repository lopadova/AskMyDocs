<?php

declare(strict_types=1);

namespace App\Agent\Debug;

use App\Mcp\Debug\McpActivityDebugPayload;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** A read-only observation of durable activity, including unfinished runs. */
final readonly class RunDiagnosticSnapshot
{
    public const MAX_SPANS = 256;

    public const MAX_METADATA_BYTES = 2_048;

    private const TERMINAL_EVENTS = ['run.completed', 'run.partial', 'run.failed', 'run.cancelled'];

    public function __construct(private McpActivityDebugPayload $sanitizer) {}

    /** @param Collection<int, AgentRun> $conversationRuns @return array<string, mixed> */
    public function build(AgentRun $run, CarbonInterface $capturedAt, Collection $conversationRuns): array
    {
        try {
            $available = Schema::hasTable('agent_diagnostic_spans');
            $spans = $available ? AgentDiagnosticSpan::query()
                ->forTenant($run->tenant_id)
                ->where('agent_run_id', $run->id)
                ->orderBy('started_at')->orderBy('id')
                ->limit(self::MAX_SPANS + 1)->get() : collect();
        } catch (Throwable) {
            $available = false;
            $spans = collect();
        }
        $truncated = $spans->count() > self::MAX_SPANS;
        $spans = $spans->take(self::MAX_SPANS);
        $lastActivity = null;
        $lastTransportActivity = null;
        $observe = function (?CarbonInterface $at, array $reference) use (&$lastActivity): void {
            if ($at !== null && ($lastActivity === null || $at->greaterThan($lastActivity['at']))) {
                $lastActivity = ['at' => $at, ...$reference];
            }
        };
        $exportedSpans = [];
        $openSpans = [];
        foreach ($spans as $span) {
            if (str_starts_with($span->phase, 'transport.')) {
                foreach ([$span->started_at, $span->ended_at] as $at) {
                    if ($at !== null && ($lastTransportActivity === null || $at->greaterThan($lastTransportActivity['at']))) {
                        $lastTransportActivity = ['at' => $at, 'source' => 'transport.span', 'span_id' => $span->id];
                    }
                }
            } else {
                $observe($span->started_at, ['source' => 'span.started', 'span_id' => $span->id]);
                $observe($span->ended_at, ['source' => 'span.ended', 'span_id' => $span->id]);
            }
            $entry = [
                'id' => $span->id,
                'parent_span_id' => $span->parent_span_id,
                'phase' => $span->phase,
                'status' => $span->status,
                'started_at' => $span->started_at?->toISOString(),
                'ended_at' => $span->ended_at?->toISOString(),
                'duration_ms' => $span->duration_ms,
                'metadata' => $this->metadata($span->metadata_json ?? []),
            ];
            $exportedSpans[] = $entry;
            if ($span->status === 'started' && $span->ended_at === null) {
                $openSpans[] = [...$entry, 'age_ms' => $this->age($span->started_at, $capturedAt)];
            }
        }
        foreach ($run->events as $event) {
            $observe($event->created_at, ['source' => 'event', 'event_id' => $event->id, 'sequence' => $event->sequence, 'type' => $event->type]);
        }
        foreach ($run->toolExecutions as $execution) {
            $observe($execution->started_at, ['source' => 'tool.started', 'tool_execution_id' => $execution->id]);
            $observe($execution->completed_at, ['source' => 'tool.completed', 'tool_execution_id' => $execution->id]);
        }

        $research = $this->researchLinks($run, $conversationRuns);
        $terminalEvent = $run->events->last(fn ($event): bool => in_array($event->type, self::TERMINAL_EVENTS, true)
            && data_get($event->payload_json, 'data.research_run_id', $run->run_id) === $run->run_id);
        $terminalEventExpected = $run->isTerminal() && $research['parent'] === null;
        $workSpans = array_values(array_filter($openSpans, fn (array $entry): bool => ! str_starts_with($entry['phase'], 'transport.')));
        $latestOpen = $workSpans === [] ? null : $workSpans[array_key_last($workSpans)];
        $warnings = [];
        if ($terminalEventExpected && $terminalEvent === null) {
            $warnings[] = 'terminal_event_not_recorded';
        }
        if ($run->isTerminal() && $openSpans !== []) {
            $warnings[] = 'open_spans_in_terminal_run';
        }
        if (! $available) {
            $warnings[] = 'diagnostic_storage_unavailable';
        }
        if ($spans->count() >= self::MAX_SPANS) {
            $warnings[] = 'span_capacity_reached';
        }

        return [
            'schema_version' => 1,
            'captured_at' => $capturedAt->toISOString(),
            'availability' => ! $available ? 'storage_unavailable' : ($spans->isEmpty() ? 'no_spans_recorded' : 'recorded'),
            'last_activity_at' => ($lastActivity['at'] ?? null)?->toISOString(),
            'activity_age_ms' => $this->age($lastActivity['at'] ?? null, $capturedAt),
            'last_activity' => $lastActivity === null ? null : [...$lastActivity, 'at' => $lastActivity['at']->toISOString()],
            'transport_last_activity' => $lastTransportActivity === null ? null : [
                ...$lastTransportActivity, 'at' => $lastTransportActivity['at']->toISOString(),
                'age_ms' => $this->age($lastTransportActivity['at'], $capturedAt),
            ],
            'waiting' => [
                'run_status' => $run->status,
                'observed_phase' => $latestOpen['phase'] ?? null,
                'span_id' => $latestOpen['id'] ?? null,
                'reported_wait_for' => $latestOpen['metadata']['wait_for'] ?? null,
                'reported_wait_reason' => $latestOpen['metadata']['wait_reason'] ?? null,
                'cause' => 'unknown',
                'worker_liveness' => 'not_probed',
            ],
            'terminal_event' => [
                'expected' => $terminalEventExpected,
                'recorded' => $terminalEvent !== null,
                'type' => $terminalEvent?->type,
                'research_events_forwarded_to_parent' => $research['parent'] !== null,
            ],
            'research_links' => $research,
            'spans' => $exportedSpans,
            'open_spans' => $openSpans,
            'warnings' => $warnings,
            'limits' => [
                'max_spans' => self::MAX_SPANS, 'max_metadata_bytes' => self::MAX_METADATA_BYTES,
                'spans_truncated' => $truncated, 'span_capacity_reached' => $spans->count() >= self::MAX_SPANS,
            ],
            'interpretation' => 'An open span records an observed start without an observed end. Activity age is not a heartbeat or proof of a stalled worker. A missing event does not establish a cause. This export is not an atomic database snapshot.',
        ];
    }

    private function age(?CarbonInterface $at, CarbonInterface $capturedAt): ?int
    {
        return $at === null ? null : max(0, (int) $at->diffInMilliseconds($capturedAt, false));
    }

    /** @return array<string, mixed> */
    private function metadata(array $metadata): array
    {
        $safe = $this->sanitizer->sanitize($metadata);
        $encoded = json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded) || strlen($encoded) > self::MAX_METADATA_BYTES) {
            return ['_truncated' => true, 'max_bytes' => self::MAX_METADATA_BYTES];
        }

        return $safe;
    }

    /** Only expose verified, bidirectional relationships inside this conversation and tenant. */
    private function researchLinks(AgentRun $run, Collection $runs): array
    {
        $parent = $runs->first(fn (AgentRun $candidate): bool => $candidate->tenant_id === $run->tenant_id
            && $candidate->conversation_id === $run->conversation_id && $candidate->project_key === $run->project_key
            && $candidate->id === data_get($run->input_json, 'research_parent_id')
            && in_array($run->id, (array) data_get($candidate->result_json, 'research_runs', []), true));
        $ids = (array) data_get($run->result_json, 'research_runs', []);
        $children = $runs->filter(fn (AgentRun $candidate): bool => $candidate->tenant_id === $run->tenant_id
            && $candidate->conversation_id === $run->conversation_id && $candidate->project_key === $run->project_key
            && in_array($candidate->id, $ids, true)
            && data_get($candidate->input_json, 'research_parent_id') === $run->id);

        return [
            'parent' => $parent === null ? null : ['id' => $parent->id, 'run_id' => $parent->run_id],
            'children' => $children->map(fn (AgentRun $child): array => [
                'id' => $child->id, 'run_id' => $child->run_id, 'status' => $child->status,
            ])->take(self::MAX_SPANS)->values()->all(),
            'children_truncated' => $children->count() > self::MAX_SPANS,
        ];
    }
}
