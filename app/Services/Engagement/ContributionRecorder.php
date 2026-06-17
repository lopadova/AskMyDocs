<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\KbContributionEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.15/W1 — appends contribution-event rows from the existing ingest /
 * promotion / chat-citation flows.
 *
 * Side-channel by design (mirrors {@see \App\Services\Kb\Analytics\SearchFailureRecorder}):
 * every call is wrapped so a recording failure NEVER breaks the caller's hot
 * path (ingestion, a chat turn). Config-gated by `askmydocs.engagement.enabled`.
 */
class ContributionRecorder
{
    /**
     * Record a single contribution event. `tenant_id` is auto-filled from
     * TenantContext by the BelongsToTenant trait when omitted.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        ?int $userId = null,
        ?int $documentId = null,
        string $projectKey = '',
        ?array $metadata = null,
        ?int $weight = null,
        ?string $tenantId = null,
    ): void {
        if (! (bool) config('askmydocs.engagement.enabled', true)) {
            return;
        }

        try {
            $attributes = [
                'user_id' => $userId,
                'document_id' => $documentId,
                'project_key' => $projectKey,
                'event' => $event,
                'weight' => $weight ?? (KbContributionEvent::WEIGHTS[$event] ?? 1),
                'metadata' => $metadata,
                'created_at' => Carbon::now(),
            ];
            // R30: when the caller knows the tenant (e.g. an afterCommit hook
            // that may run in a queue worker without ambient TenantContext),
            // set it explicitly so the BelongsToTenant auto-fill can't mis-attribute.
            if ($tenantId !== null && $tenantId !== '') {
                $attributes['tenant_id'] = $tenantId;
            }

            KbContributionEvent::create($attributes);
        } catch (Throwable $e) {
            // Never propagate — engagement tracking is best-effort telemetry.
            Log::warning('ContributionRecorder: failed to record event.', [
                'event' => $event,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
