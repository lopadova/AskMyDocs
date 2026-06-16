<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendDigestWebhookJob;
use App\Mail\DigestMail;
use App\Models\KnowledgeDocument;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Digest\AiDigestNarrator;
use App\Services\Digest\DigestComposer;
use App\Services\Digest\DigestPayload;
use App\Services\Digest\Renderers\DigestRendererRegistry;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * v8.15/W2 — send the rich KB engagement digest (metrics + sections + AI
 * narrative) to email + the configured team channels (Discord/Slack/Teams).
 *
 * One row per tenant. The AI narrative is composed once and shared across every
 * channel so email and the cards never drift. Channels with no configured URL
 * are skipped. `--dry-run` composes + renders without sending; `--preview`
 * dumps the composed payload + rendered cards as JSON.
 */
final class DigestSendCommand extends Command
{
    protected $signature = 'digest:send
        {--frequency=weekly : weekly|monthly}
        {--tenant= : Restrict to a single tenant}
        {--channel= : Restrict to one channel (email|discord|slack|teams)}
        {--dry-run : Compose + render but do not send}
        {--preview : Print the composed payload + rendered cards as JSON (implies --dry-run)}';

    protected $description = 'Send the rich KB engagement digest (metrics + AI narrative) to email + Discord/Slack/Teams.';

    public function handle(
        DigestComposer $composer,
        AiDigestNarrator $narrator,
        DigestRendererRegistry $renderers,
        TenantContext $tenants,
    ): int {
        $frequency = $this->option('frequency') === 'monthly' ? 'monthly' : 'weekly';
        $onlyChannel = trim((string) ($this->option('channel') ?? '')) ?: null;
        $preview = (bool) $this->option('preview');
        $dryRun = $preview || (bool) $this->option('dry-run');

        $previousTenant = $tenants->current();

        try {
            foreach ($this->resolveTenantIds() as $tenantId) {
                $tenants->set($tenantId);

                $payload = $composer->composeForTenant($frequency);
                $payload->narrative = $narrator->narrate($payload);

                if ($preview) {
                    $this->previewTenant($payload, $renderers, $onlyChannel);

                    continue;
                }

                if (! $dryRun) {
                    // Persist the in-app feed entry (W3) so the SPA "This week
                    // in your KB" card has the generated digest to show.
                    \App\Models\EngagementDigestFeedEntry::create([
                        'tenant_id' => $payload->tenantId,
                        'frequency' => $payload->frequency,
                        'period_start' => $payload->periodStart,
                        'period_end' => $payload->periodEnd,
                        'payload' => $payload->toArray(),
                        'created_at' => now(),
                    ]);
                }

                $sent = $this->dispatchTenant($payload, $renderers, $onlyChannel, $dryRun, $frequency);
                $verb = $dryRun ? 'would send' : 'sent';
                $this->info("[{$tenantId}] {$frequency} digest {$verb}: email={$sent['email']} channels=".implode(',', $sent['channels'] ?: ['none']));
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{email:int, channels:list<string>}
     */
    private function dispatchTenant(
        DigestPayload $payload,
        DigestRendererRegistry $renderers,
        ?string $onlyChannel,
        bool $dryRun,
        string $frequency,
    ): array {
        $emailCount = 0;
        $channelsSent = [];

        // Email — streamed in chunks (R3: never materialise the full recipient
        // set for a large tenant), filtered by each user's digest frequency
        // preference (W3). Counts in dry-run, queues otherwise.
        if ($onlyChannel === null || $onlyChannel === 'email') {
            $emailCount = $this->streamEmailRecipients(
                $payload->tenantId,
                $frequency,
                $dryRun
                    ? null
                    : static fn (User $user) => Mail::to($user)->queue(DigestMail::fromPayload($payload)),
            );
        }

        // Team channels
        foreach (['discord', 'slack', 'teams'] as $channel) {
            if ($onlyChannel !== null && $onlyChannel !== $channel) {
                continue;
            }
            $url = (string) config("askmydocs.notifications.channels.{$channel}.url", '');
            if ($url === '' || ! $renderers->has($channel)) {
                continue;
            }
            $channelsSent[] = $channel;
            if (! $dryRun) {
                SendDigestWebhookJob::dispatch(
                    channelName: $channel,
                    tenantId: $payload->tenantId,
                    url: $url,
                    payload: $renderers->forOrFail($channel)->render($payload),
                    hmacSecret: (string) config("askmydocs.notifications.channels.{$channel}.secret", '') ?: null,
                );
            }
        }

        return ['email' => $emailCount, 'channels' => $channelsSent];
    }

    private function previewTenant(DigestPayload $payload, DigestRendererRegistry $renderers, ?string $onlyChannel): void
    {
        $out = ['payload' => $payload->toArray(), 'cards' => []];
        foreach ($renderers->channels() as $channel) {
            if ($onlyChannel !== null && $onlyChannel !== $channel) {
                continue;
            }
            $out['cards'][$channel] = $renderers->forOrFail($channel)->render($payload);
        }
        $this->line(json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Stream users eligible for the digest at $frequency, in chunkById(500)
     * batches (R3 — no full materialisation on a large tenant). Eligible =
     * email-enabled notification preference AND digest-frequency match:
     *  - weekly run  → users with no digest_preferences row (default weekly)
     *                  OR an explicit frequency='weekly'; excludes monthly/off.
     *  - monthly run → users with an explicit frequency='monthly'.
     * Invokes $each per user when provided (null = count only, for dry-run).
     *
     * @param  null|callable(User):void  $each
     */
    private function streamEmailRecipients(string $tenantId, string $frequency, ?callable $each): int
    {
        $count = 0;

        $query = User::query()
            ->whereExists(function ($query) use ($tenantId): void {
                // Unqualified `tenant_id` is unambiguous here — the subquery's
                // only FROM table is notification_preferences (the correlated
                // `users` row carries no tenant_id). R30 scope marker.
                $query->select(DB::raw(1))
                    ->from('notification_preferences')
                    ->whereColumn('notification_preferences.user_id', 'users.id')
                    ->where('tenant_id', $tenantId)
                    ->where('notification_preferences.channel', NotificationPreference::CHANNEL_EMAIL)
                    ->where('notification_preferences.enabled', true);
            });

        if ($frequency === 'monthly') {
            $query->whereExists(fn ($q) => $this->digestFrequencyExists($q, $tenantId, ['monthly']));
        } else {
            // Weekly: default-in (no row) OR explicit weekly; exclude any row
            // whose frequency is NOT weekly (monthly / off opt out).
            $query->whereNotExists(fn ($q) => $this->digestFrequencyExists($q, $tenantId, ['monthly', 'off']));
        }

        $query->chunkById(500, function ($users) use (&$count, $each): void {
            foreach ($users as $user) {
                $count++;
                if ($each !== null) {
                    $each($user);
                }
            }
        });

        return $count;
    }

    /**
     * Correlated digest_preferences EXISTS subquery for the given frequencies.
     *
     * @param  \Illuminate\Database\Query\Builder  $q
     * @param  list<string>  $frequencies
     */
    private function digestFrequencyExists($q, string $tenantId, array $frequencies): void
    {
        $q->select(DB::raw(1))
            ->from('digest_preferences')
            ->whereColumn('digest_preferences.user_id', 'users.id')
            ->where('tenant_id', $tenantId)
            ->whereIn('digest_preferences.frequency', $frequencies);
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(): array
    {
        $explicit = trim((string) ($this->option('tenant') ?? ''));
        if ($explicit !== '') {
            return [$explicit];
        }

        $tenantIds = KnowledgeDocument::query()
            ->withTrashed()
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();

        return $tenantIds === [] ? [app(TenantContext::class)->current()] : $tenantIds;
    }
}
