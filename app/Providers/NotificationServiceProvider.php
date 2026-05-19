<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Notifications\ChannelRegistry;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TeamsChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Scopes\AccessScopeScope;
use App\Notifications\Events\BaseNotificationEvent;
use App\Notifications\Events\CollectionNewMember;
use App\Notifications\Events\KbCanonicalPromoted;
use App\Notifications\Events\KbDecisionDebtThreshold;
use App\Notifications\Events\KbDocumentChanged;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * v8.0/W1.2 — wires the notification dispatch pipeline.
 *
 * - Binds `ChannelRegistry` as a singleton so the runtime adapter
 *   map is shared across the request.
 * - Registers `NotificationDispatcher` as the listener for every
 *   concrete `BaseNotificationEvent` subclass shipped in W1.2.
 *   The dispatcher reads recipients off the event, looks up
 *   per-user preferences, inserts the audit row, and invokes
 *   channel adapters (NullChannel fallback until W1.3 lands real
 *   adapters).
 * - Wires the production publishers via Eloquent `created` hooks
 *   so EVERY ingestion / promotion path (HTTP, CLI, Flow, future
 *   connectors) fires the matching event without per-call-site
 *   plumbing:
 *     - `KnowledgeDocument::created` → `KbDocumentChanged`
 *       (`'created'` for first-version inserts, `'modified'`
 *       when a prior version exists for the same
 *       `(tenant_id, project_key, source_path)`).
 *     - `KbCanonicalAudit::created` with `event_type='promoted'`
 *       → `KbCanonicalPromoted`. The audit row is the canonical
 *       seam: `WriteCanonicalMarkdownStep` writes it inside the
 *       saga transaction, so synchronous + flow-based promotions
 *       both end up here without separate wiring.
 *   `KbDecisionDebtThreshold` and `CollectionNewMember` stay as
 *   listenable contracts in W1.2 — their publisher hooks land in
 *   W4 (decision-debt cron) and W6 (Living Collections evaluator).
 *
 * Listed in `bootstrap/providers.php` AFTER AppServiceProvider so
 * the TenantContext singleton it depends on is bound first.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class);
    }

    public function boot(): void
    {
        // v8.0/W1.3 — register the two baseline channel adapters
        // (in_app + email). v8.0/W2.1 — register the 4 external
        // adapters (discord / slack / teams / generic webhook)
        // ONLY when their corresponding URL is configured; an
        // unconfigured external channel stays unregistered so the
        // ChannelRegistry falls through to NullChannel for any
        // (event, user) pair that has an enabled preference for
        // it — see `registerExternalChannels()` below.
        //
        // Tests that need the NullChannel fallback can clear the
        // registry via `$this->app->forgetInstance(ChannelRegistry::class)`
        // before re-resolving.
        $registry = $this->app->make(ChannelRegistry::class);
        $registry->register(new InAppChannel());
        $registry->register(new EmailChannel());
        $this->registerExternalChannels($registry);

        $events = [
            KbDocumentChanged::class,
            KbCanonicalPromoted::class,
            KbDecisionDebtThreshold::class,
            CollectionNewMember::class,
        ];

        foreach ($events as $eventClass) {
            Event::listen(
                $eventClass,
                static fn (BaseNotificationEvent $event) => app(NotificationDispatcher::class)->handle($event),
            );
        }

        $this->wireDomainPublishers();
    }

    /**
     * v8.0/W2.1 — register the 4 optional external webhook channels
     * (Discord, Slack, Teams, generic Webhook) ONLY when their URL
     * is configured. An unconfigured channel stays unregistered so
     * the dispatcher's ChannelRegistry::for() lookup falls through
     * to NullChannel (which appends `'skipped'` to the log) instead
     * of dispatching a real job against an empty URL.
     *
     * This also makes the architecture-level cost of opting OUT of
     * a channel a single env var line rather than a code change —
     * operators can run AskMyDocs with only `in_app` + `email`
     * enabled and never see Discord/Slack/Teams instances in
     * memory.
     */
    private function registerExternalChannels(ChannelRegistry $registry): void
    {
        $channelClasses = [
            'discord' => DiscordChannel::class,
            'slack' => SlackChannel::class,
            'teams' => TeamsChannel::class,
            'webhook' => WebhookChannel::class,
        ];

        foreach ($channelClasses as $key => $className) {
            $url = (string) config("askmydocs.notifications.channels.{$key}.url", '');
            if ($url === '') {
                continue;
            }
            /** @var \App\Notifications\Channels\NotificationChannelInterface $instance */
            $instance = new $className();
            $registry->register($instance);
        }
    }

    /**
     * Bridge Eloquent `created` hooks to {@see NotificationPublisher}.
     * Each hook is wrapped in `DB::afterCommit` so the publisher
     * (and downstream `NotificationDispatcher`) only run if the
     * transaction that created the row actually commits — otherwise
     * a rolled-back ingest would still fan out notification emails.
     *
     * The recipient resolution + event dispatch happens INSIDE
     * `afterCommit`; outside a transaction Laravel fires it
     * immediately. Failures inside the closure are swallowed and
     * logged so a notification-pipeline outage never breaks the
     * underlying domain mutation.
     */
    private function wireDomainPublishers(): void
    {
        KnowledgeDocument::created(static function (KnowledgeDocument $document): void {
            DB::afterCommit(static function () use ($document): void {
                try {
                    $tenantId = (string) ($document->tenant_id ?? '');
                    $projectKey = (string) ($document->project_key ?? '');
                    if ($tenantId === '' || $projectKey === '') {
                        return;
                    }
                    // R10 / R30 + Copilot PR #189: bypass
                    // `AccessScopeScope` for this SYSTEM-side classification
                    // lookup. The scope filters reads by the currently
                    // authenticated user's project membership, but the
                    // dispatcher runs in the context of whoever triggered
                    // the ingest — which is often not authorized to see
                    // prior versions (canonical predecessors, deny-ACL
                    // rows, scope_allowlist mismatches). Without the
                    // bypass a re-ingest by a user who can't read the
                    // archived row gets misclassified as `'created'`
                    // instead of `'modified'`. `withTrashed()` only
                    // removes the SoftDeletes scope, not AccessScopeScope.
                    $isModified = KnowledgeDocument::withoutGlobalScope(AccessScopeScope::class)
                        ->withTrashed()
                        ->where('tenant_id', $tenantId)
                        ->where('project_key', $projectKey)
                        ->where('source_path', $document->source_path)
                        ->where('id', '!=', $document->id)
                        ->exists();

                    app(NotificationPublisher::class)->publishKbDocumentChanged(
                        $document,
                        $isModified,
                    );
                } catch (\Illuminate\Database\QueryException $e) {
                    // Non-integrity DB errors (deadlocks, connection
                    // drops, schema mismatches) re-raised by the
                    // dispatcher or evaluateAcl* lookups MUST be
                    // surfaced at error level — silently warning would
                    // hide operational incidents. We still swallow so
                    // the original ingest write is not aborted by a
                    // failing notification side-effect, but the
                    // operator's log pipeline sees this as a real
                    // alert.
                    \Illuminate\Support\Facades\Log::error(
                        'NotificationServiceProvider: KbDocumentChanged DB failure',
                        ['document_id' => $document->id, 'sqlstate' => $e->getCode(), 'error' => $e->getMessage()],
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'NotificationServiceProvider: KbDocumentChanged publisher failed',
                        ['document_id' => $document->id, 'error' => $e->getMessage()],
                    );
                }
            });
        });

        KbCanonicalAudit::created(static function (KbCanonicalAudit $audit): void {
            if ((string) $audit->event_type !== 'promoted') {
                return;
            }
            DB::afterCommit(static function () use ($audit): void {
                try {
                    $tenantId = (string) ($audit->tenant_id ?? '');
                    $projectKey = (string) ($audit->project_key ?? '');
                    if ($tenantId === '' || $projectKey === '') {
                        return;
                    }
                    app(NotificationPublisher::class)->publishKbCanonicalPromoted(
                        tenantId: $tenantId,
                        projectKey: $projectKey,
                        docId: $audit->doc_id === null ? null : (string) $audit->doc_id,
                        slug: $audit->slug === null ? null : (string) $audit->slug,
                        actor: $audit->actor === null ? null : (string) $audit->actor,
                    );
                } catch (\Illuminate\Database\QueryException $e) {
                    // See KbDocumentChanged hook above — non-integrity
                    // DB failures get error-level logging so operators
                    // see deadlocks / schema errors instead of having
                    // them buried in warnings.
                    \Illuminate\Support\Facades\Log::error(
                        'NotificationServiceProvider: KbCanonicalPromoted DB failure',
                        ['audit_id' => $audit->id, 'sqlstate' => $e->getCode(), 'error' => $e->getMessage()],
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'NotificationServiceProvider: KbCanonicalPromoted publisher failed',
                        ['audit_id' => $audit->id, 'error' => $e->getMessage()],
                    );
                }
            });
        });
    }
}
