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

                $sent = $this->dispatchTenant($payload, $renderers, $onlyChannel, $dryRun);
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
    ): array {
        $emailCount = 0;
        $channelsSent = [];

        // Email
        if ($onlyChannel === null || $onlyChannel === 'email') {
            $recipients = $this->emailRecipients($payload->tenantId);
            $emailCount = count($recipients);
            if (! $dryRun) {
                foreach ($recipients as $user) {
                    Mail::to($user)->queue(DigestMail::fromPayload($payload));
                }
            }
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
                    payload: $renderers->for($channel)->render($payload),
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
            $out['cards'][$channel] = $renderers->for($channel)->render($payload);
        }
        $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Users with at least one email-enabled notification preference in the tenant.
     *
     * @return list<User>
     */
    private function emailRecipients(string $tenantId): array
    {
        $userIds = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', NotificationPreference::CHANNEL_EMAIL)
            ->where('enabled', true)
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($userIds === []) {
            return [];
        }

        return User::query()->whereIn('id', $userIds)->get()->all();
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
