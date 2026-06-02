<?php

declare(strict_types=1);

namespace App\Services\Kb\Analytics;

use App\Models\KbSearchFailure;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.8/W4 — records a "content gap": a question the KB could not answer
 * (a refusal — deterministic grounding gate OR LLM self-refusal sentinel).
 *
 * Increments a per-(tenant, project, normalized query, reason) rollup so the
 * admin "Content Gaps" panel can rank what to write next. Like
 * {@see \App\Services\ChatLog\ChatLogManager}, this is a side-channel that
 * MUST NEVER break the chat hot path — every failure is swallowed and logged.
 */
final class SearchFailureRecorder
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function record(?string $projectKey, string $query, string $reason): void
    {
        try {
            if (! (bool) config('kb.content_gaps.enabled', true)) {
                return;
            }

            $normalized = $this->normalize($query);
            if ($normalized === '') {
                return;
            }

            $tenantId = $this->tenants->current();
            $hash = hash('sha256', $normalized);
            $project = $projectKey ?? '';

            // firstOrCreate on the composite-unique anchor, then an ATOMIC
            // increment so concurrent refusals of the same query don't lose
            // counts (the increment is `occurrences = occurrences + 1` in SQL).
            $row = KbSearchFailure::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'project_key' => $project,
                    'query_hash' => $hash,
                    'reason' => $reason,
                ],
                [
                    'normalized_query' => $normalized,
                    'query_text' => mb_substr($query, 0, 1000),
                    'occurrences' => 0,
                    'last_seen_at' => now(),
                ],
            );

            $row->increment('occurrences');
            $row->forceFill([
                'query_text' => mb_substr($query, 0, 1000),
                'last_seen_at' => now(),
                // A recurring gap is "re-opened" — clear a stale resolution so
                // it resurfaces in the ranked list.
                'resolved_at' => null,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('SearchFailureRecorder: failed to record content gap', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lowercase, trim, collapse internal whitespace, and bound the length so
     * the rollup buckets near-identical phrasings together.
     */
    private function normalize(string $query): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($query)) ?? '';

        return mb_substr(mb_strtolower($collapsed), 0, 500);
    }
}
