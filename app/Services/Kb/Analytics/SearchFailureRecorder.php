<?php

declare(strict_types=1);

namespace App\Services\Kb\Analytics;

use App\Models\KbSearchFailure;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
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

            // Insert-or-atomic-increment without losing a count under
            // concurrency. A plain `lockForUpdate` read+write does NOT cover
            // the MISSING-ROW race: two concurrent refusals both see no row,
            // both INSERT, and the loser hits the UNIQUE constraint
            // (Copilot review). Instead: try the INSERT, and on the unique
            // violation fall back to an ATOMIC `occurrences = occurrences + 1`
            // UPDATE — portable across pgsql + sqlite, no lost counts.
            try {
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
            } catch (UniqueConstraintViolationException) {
                KbSearchFailure::query()
                    ->forTenant($tenantId)
                    ->where('project_key', $project)
                    ->where('query_hash', $hash)
                    ->where('reason', $reason)
                    ->update([
                        'occurrences' => DB::raw('occurrences + 1'),
                        'query_text' => $truncated,
                        'last_seen_at' => $ts,
                        // A recurring gap is "re-opened" — clear a stale
                        // resolution so it resurfaces in the ranked list.
                        'resolved_at' => null,
                    ]);
            }
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
