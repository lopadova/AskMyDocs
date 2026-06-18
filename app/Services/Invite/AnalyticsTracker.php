<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\InviteAnalyticsEvent;
use App\Services\Invite\Support\PiiHasher;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Append-only funnel event recorder (docs/11-analytics.md).
 *
 * Like ChatLogManager, analytics MUST NEVER break the hot path: every record()
 * is wrapped in try/catch. Ingestion is idempotent on (tenant, event_id) via
 * insertOrIgnore, so a replayed domain event or a job retry collapses to one
 * row. The actor is pseudonymized to a non-reversible HMAC; no raw PII enters
 * the log.
 */
final class AnalyticsTracker
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PiiHasher $hasher,
    ) {
    }

    /**
     * @param  array{account_id?: int|null, campaign_id?: int|null, code_id?: int|null, referral_id?: int|null, context?: array<string, mixed>}  $attrs
     */
    public function record(string $eventType, string $eventId, array $attrs = []): void
    {
        try {
            $accountId = $attrs['account_id'] ?? null;

            // insertOrIgnore makes the UNIQUE(tenant_id, event_id) collision a
            // silent no-op — idempotent ingestion, no second row.
            DB::table('invite_analytics_events')->insertOrIgnore([
                'tenant_id' => $this->tenant->current(),
                'event_id' => $eventId,
                'event_type' => $eventType,
                'actor_hash' => $accountId !== null ? $this->hasher->hash('account:' . $accountId) : null,
                'campaign_id' => $attrs['campaign_id'] ?? null,
                'code_id' => $attrs['code_id'] ?? null,
                'referral_id' => $attrs['referral_id'] ?? null,
                'context' => isset($attrs['context']) ? json_encode($attrs['context']) : null,
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Analytics is best-effort; never propagate into the user path.
        }
    }
}
