<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * v8.0/W1.2 — base class for every notification-eligible domain event.
 *
 * Pattern (ADR 0012): the publisher resolves recipients in its
 * domain context (IngestDocumentJob knows the project's subscribers,
 * CanonicalWriter knows the promotion approver, etc.) and constructs
 * the event with the recipient list already attached. The event is a
 * dumb DTO; the `NotificationDispatcher` listener does NOT resolve
 * recipients on its own — it just iterates `$event->recipients()`.
 *
 * A recipient is either a `User` (per-user fan-out, the common case)
 * OR `null` (a single tenant-wide system row, used by dual-mode
 * events like `EVENT_KB_DECISION_DEBT_THRESHOLD` when the dispatcher
 * policy chooses the tenant-wide branch — see ADR 0012 §Dispatcher).
 *
 * `tenantId` is REQUIRED at construction — publishers MUST pass the
 * explicit tenant the event belongs to (typically `TenantContext::current()`
 * for the user-facing publisher path, or the domain-owned tenant for
 * cross-tenant maintenance jobs). The dispatcher uses it verbatim to
 * stamp `notification_events.tenant_id`; no implicit defaulting happens
 * here to avoid silently emitting events under the wrong tenant when
 * the publisher forgot to set the context.
 */
abstract class BaseNotificationEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, User|null>  $recipients
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $recipients,
        public readonly array $payload,
        public readonly string $tenantId,
    ) {
    }

    /**
     * Constant from `NotificationEvent::EVENT_*` identifying this
     * event in the persistence layer. MUST match a value in
     * `notification_preferences.event_type` so the dispatcher's
     * preference lookup resolves.
     */
    abstract public function eventType(): string;

    /**
     * @return array<int, User|null>
     */
    public function recipients(): array
    {
        return $this->recipients;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }
}
