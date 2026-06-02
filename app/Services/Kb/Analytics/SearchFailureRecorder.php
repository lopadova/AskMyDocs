<?php

declare(strict_types=1);

namespace App\Services\Kb\Analytics;

use App\Models\KbSearchFailure;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
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

            $tenantId  = $this->tenants->current();
            $hash      = hash('sha256', $normalized);
            $project   = $projectKey ?? '';
            $truncated = mb_substr($query, 0, 1000);
            $ts        = now();

            // R21 — rate counter: lock-read-write in ONE transaction so two
            // concurrent refusals of the same query can't race on the UNIQUE
            // anchor (firstOrCreate without a lock would let both threads win
            // the SELECT, both attempt INSERT, and the loser's occurrence is
            // silently swallowed by the outer catch).
            DB::transaction(function () use (
                $tenantId, $project, $hash, $reason, $normalized, $truncated, $ts,
            ): void {
                $row = KbSearchFailure::query()
                    ->where('tenant_id', $tenantId)
                    ->where('project_key', $project)
                    ->where('query_hash', $hash)
                    ->where('reason', $reason)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    KbSearchFailure::create([
                        'tenant_id'        => $tenantId,
                        'project_key'      => $project,
                        'query_hash'       => $hash,
                        'reason'           => $reason,
                        'normalized_query' => $normalized,
                        'query_text'       => $truncated,
                        'occurrences'      => 1,
                        'last_seen_at'     => $ts,
                    ]);
                } else {
                    $row->occurrences   += 1;
                    $row->query_text    = $truncated;
                    $row->last_seen_at  = $ts;
                    // A recurring gap is "re-opened" — clear a stale resolution
                    // so it resurfaces in the ranked list.
                    $row->resolved_at = null;
                    $row->save();
                }
            });
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
