<?php

declare(strict_types=1);

namespace App\Providers;

use App\Notifications\ChannelRegistry;
use App\Notifications\Events\BaseNotificationEvent;
use App\Notifications\Events\CollectionNewMember;
use App\Notifications\Events\KbCanonicalPromoted;
use App\Notifications\Events\KbDecisionDebtThreshold;
use App\Notifications\Events\KbDocumentChanged;
use App\Notifications\NotificationDispatcher;
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
    }
}
