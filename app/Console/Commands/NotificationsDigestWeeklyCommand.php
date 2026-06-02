<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\WeeklyDigestMail;
use App\Models\NotificationDigest;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\NotificationSubjects;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * v8.7/W2 — weekly notification digest (closes roadmap R6).
 *
 * The `notification_digests` table + model shipped in v8.0/W1.1 but nothing
 * wrote or read them. This command, scheduled weekly, does both:
 *
 *  1. Aggregates the past 7 days of `notification_events` per tenant into a
 *     `notification_digests` row (one per `(tenant_id, week_start_date)`,
 *     idempotent via `updateOrCreate`).
 *  2. Emails each user their OWN roundup (only their `notification_events`)
 *     — every user with at least one `email`-enabled preference and at
 *     least one event in the window. Stamps `sent_at` + `recipients_count`.
 *
 * A user can therefore keep per-event email OFF (noisy) and still receive
 * the Monday digest, or vice-versa — the StackOverflow-style choice.
 */
final class NotificationsDigestWeeklyCommand extends Command
{
    protected $signature = 'notifications:digest-weekly
                            {--tenant= : Restrict the digest to one tenant}';

    protected $description = 'Aggregate the week\'s notifications and email each user their roundup';

    public function handle(TenantContext $tenants): int
    {
        $windowEnd = CarbonImmutable::now();
        $windowStart = $windowEnd->subDays(7);
        $weekStartDate = $windowStart->toDateString();

        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === []) {
            $this->info('No notification events found. Nothing to digest.');

            return self::SUCCESS;
        }

        $previousTenant = $tenants->current();

        try {
            foreach ($tenantIds as $tenantId) {
                $tenants->set($tenantId);
                $sent = $this->digestTenant($tenantId, $windowStart, $weekStartDate);
                $this->info("[{$tenantId}] week_start={$weekStartDate} recipients={$sent}");
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    private function digestTenant(
        string $tenantId,
        CarbonImmutable $windowStart,
        string $weekStartDate,
    ): int {
        $events = NotificationEvent::query()
            ->forTenant($tenantId)
            ->where('created_at', '>=', $windowStart)
            ->get(['user_id', 'event_type', 'payload', 'created_at']);

        if ($events->isEmpty()) {
            return 0;
        }

        // Tenant-level aggregate persisted for the record / future admin
        // panel (one row per tenant per week, idempotent on re-run).
        // `whereDate` matches the stored value regardless of the time
        // component the `date` cast appends ('Y-m-d 00:00:00'), which a
        // plain `updateOrCreate(['week_start_date' => 'Y-m-d'])` would miss
        // and re-INSERT into the composite unique.
        $digest = NotificationDigest::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('week_start_date', $weekStartDate)
            ->first()
            ?? new NotificationDigest(['tenant_id' => $tenantId, 'week_start_date' => $weekStartDate]);
        $digest->payload = $this->aggregate($events);
        $digest->save();

        // Per-user roundups: every user with an email-enabled preference
        // who actually had ≥1 event this window.
        $emailUserIds = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', NotificationPreference::CHANNEL_EMAIL)
            ->where('enabled', true)
            ->distinct()
            ->pluck('user_id');

        $recipients = 0;
        foreach ($emailUserIds as $userId) {
            $userEvents = $events->where('user_id', $userId);
            if ($userEvents->isEmpty()) {
                continue;
            }

            $user = User::query()->find($userId);
            if ($user === null) {
                continue;
            }

            Mail::to($user)->queue(new WeeklyDigestMail(
                weekStartDate: $weekStartDate,
                groups: $this->aggregate($userEvents),
            ));
            $recipients++;
        }

        $digest->update(['sent_at' => CarbonImmutable::now(), 'recipients_count' => $recipients]);

        return $recipients;
    }

    /**
     * Aggregate a set of events into per-event-type groups with a count
     * and up to 5 sample titles. Shared by the tenant-level digest row and
     * the per-user email body so the two never drift.
     *
     * @param  \Illuminate\Support\Collection<int, NotificationEvent>  $events
     * @return list<array{event_type: string, label: string, count: int, samples: list<string>}>
     */
    private function aggregate($events): array
    {
        $groups = [];
        foreach ($events->groupBy('event_type') as $eventType => $rows) {
            $samples = [];
            foreach ($rows as $row) {
                $payload = (array) ($row->payload ?? []);
                $title = $payload['title'] ?? $payload['slug'] ?? null;
                if (is_string($title) && $title !== '' && count($samples) < 5 && ! in_array($title, $samples, true)) {
                    $samples[] = $title;
                }
            }

            $groups[] = [
                'event_type' => (string) $eventType,
                'label' => NotificationSubjects::forEventType((string) $eventType),
                'count' => $rows->count(),
                'samples' => $samples,
            ];
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(): array
    {
        $explicit = (string) ($this->option('tenant') ?? '');
        if ($explicit !== '') {
            return [$explicit];
        }

        return NotificationEvent::query()
            ->distinct()
            // R30: intentionally unscoped — this bootstrap query discovers the
            // TENANT SET by reading only the tenant_id column. All event reads
            // and digest writes inside digestTenant() are tenant_id-scoped. The
            // TenantReadScopeTest passes this file on the forTenant / where('tenant_id')
            // markers in digestTenant(); no ALLOWLIST entry is needed.
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
