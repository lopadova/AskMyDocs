# ADR 0012 — v8.0 Notification system (DB-backed multi-channel)

**Status:** Accepted (2026-05-18)
**Cycle:** v8.0
**Supersedes:** none
**Related:** PLAN-v8.0-killer-features.md §C.1 (W1 dispatch through W1.5 prune)

## Context

Pre-v8.0 the host AskMyDocs had **zero notification infrastructure**:
no `notifications` migration, no model, no controller, no bell UI, no
external-channel adapter. `bootstrap/app.php` carried a placeholder
comment about wiring `notifications:prune` once a DatabaseNotification
model lands, but the prune slot itself was not active.

Multiple v8.0 features now want to fire user-visible events:

- W1 — KB doc created / modified / canonical promoted
- W4 — decision-debt threshold reached for a tenant
- W6 — new doc joins a Living Collection
- W8 — quarterly compliance differential report ready

Each event needs to reach the right users on the right channels with
per-user / per-event / per-channel granularity. Users need an in-app
bell + a panel listing their unread + read history. Tenant admins
need defaults and digests.

## Decision

The host ships a database-backed multi-channel notification system in
W1 with the following invariants:

### Schema (three tables, all tenant-aware per R31)

- **`notification_events`** — one row per fan-out emission. `user_id`
  nullable to cover the dual-mode `EVENT_KB_DECISION_DEBT_THRESHOLD`
  event (the W2 dispatcher policy chooses per-user fan-out OR a
  single tenant-wide system row). `payload` JSON carries event-
  specific data; `channel_dispatch_log` JSON records per-channel
  delivery outcomes for forensics. Indexes:
  - `(tenant_id, user_id, dismissed_at, read_at, created_at)` — bell
    hot path (unread + undismissed for this user, newest first).
  - `(tenant_id, event_type)` — admin event-type filter.
  - `(tenant_id, created_at)` — retention sweep.
- **`notification_preferences`** — per-user-per-event-per-channel
  toggle matrix with `UNIQUE(tenant_id, user_id, event_type,
  channel)` for idempotent upsert + `(tenant_id, event_type, channel,
  enabled, user_id)` covering scan for the W2 dispatcher lookup.
- **`notification_digests`** — weekly tenant-aggregated payload with
  `UNIQUE(tenant_id, week_start_date)`. No `user_id` FK; digests are
  per-tenant, fan-out to recipients happens at render time.

### Channels (W1 ships `in_app` + `email`; W2 extends)

- `in_app` — writes to `notification_events`, polling 30s from the
  bell (no Reverb in v8.0 — defer WebSocket).
- `email` — Laravel `Mail::to($user)->queue(NotificationMail)` with
  MJML template + tenant-scoped HMAC unsubscribe link.
- W2 adds `discord` + `slack` + `teams` + `webhook` adapters
  implementing the same `NotificationChannelInterface`. External
  adapters use HTTP POST with HMAC signature + rate-limit + retry
  3× with backoff [5, 30, 120]s.

### Dispatcher (W1.2)

Laravel event-listener pipeline: every interesting mutation emits a
domain event (`KbDocumentChanged` / `KbCanonicalPromoted` etc.).
The `NotificationDispatcher` listener works per target user as
follows:

1. Reads `notification_preferences` for (tenant, user, event) and
   collects every enabled channel.
2. If at least one channel is enabled, inserts **one**
   `notification_events` row with `channel_dispatch_log = []`. The
   row's existence is the audit trail and the bell-feed source for
   in-app subscribers; it is created regardless of which specific
   channels are enabled, so an `email`-only user still has an
   observable row + log.
3. For each enabled channel, invokes
   `NotificationChannelInterface::send($event, $user, $eventRowId)`.
   The channel appends ONE entry to that row's `channel_dispatch_log`
   array with `{channel, status, at, error?}`. The dispatcher
   serialises channel invocations per row to avoid the
   read/append/write race that two parallel channels writing to the
   same JSON array would otherwise hit (see Consequences below).

Aggregate-mode events (e.g. weekly digest) upsert into the current
week's `notification_digests` payload instead of firing immediately;
the weekly digest job (planned slot `notifications:digest-weekly`,
lands in W2) reads + ships + stamps `sent_at` + `recipients_count`.

### Defaults + preferences UI

- Per-event-type default in `config('askmydocs.notifications.defaults')`
  (config file introduced in W2). Tenant admin overrides at
  `/app/admin/notifications/defaults`.
- Per-user preferences grid at `/app/account/notifications` (event-
  type rows × channel cols). New `User` creation populates the
  preferences matrix from tenant defaults via the `User::created`
  hook.

### Retention

W1.5 wires `notifications:prune` as the 13th scheduler slot
(`dailyAt('04:10')` by default; Tier-1 env override via
`SCHEDULE_NOTIFICATIONS_PRUNE_CRON`). Retention window:
`config('askmydocs.notifications.retention_days', 90)`. Setting the
env var to `0` disables the prune.

## Consequences

**Positive:**
- The host gains a generic notification surface every v8.0 feature
  (and every future feature) plugs into without bespoke wiring.
- The matrix shape (event × channel per-user) supports the granular
  on/off requested by Lorenzo without proliferating per-feature
  preference columns.
- The dispatcher is event-driven, so adding a new event type is one
  Listener registration + one event constant + one default-policy
  row.
- The digest table decouples instantaneous events from aggregate
  weekly email; reduces inbox noise and email-provider quota burn.

**Negative / tradeoffs:**
- Polling 30s for the bell instead of real-time WebSocket is a
  conscious deferral. Acceptable until tenant SLAs require <30s
  end-to-end latency; revisit with Reverb in v8.x / v9.0.
- 6 channels × N event types × M users = O(N·M) preference rows per
  tenant. The covering index on
  `(tenant_id, event_type, channel, enabled, user_id)` keeps the
  dispatcher hot path index-only; the upsert UNIQUE caps memory at
  one row per cell. For very large tenants (10k+ users × 6+ events
  × 6 channels = ~360k rows) the table is healthy on Postgres but
  worth watching the dispatcher latency once it lands.
- The dual-mode `EVENT_KB_DECISION_DEBT_THRESHOLD` (per-user OR
  tenant-wide) introduces a semantic choice the dispatcher policy
  must make. Documented explicitly on the constant + test-locked
  in `NotificationModelsLifecycleTest`. If future events ALSO need
  dual-mode, lift the policy out of the dispatcher into a
  per-event policy table.
- **`channel_dispatch_log` append concurrency.** Because each row's
  JSON array is appended by every enabled channel, naive parallel
  appends (two channels processing the same row concurrently from
  separate workers) would read/append/write-race and drop an
  entry. The dispatcher MUST serialise channel invocations per
  `eventRowId` — either (a) by running channels synchronously
  within a single listener call after the row insert (W1.2/W1.3
  baseline), or (b) by using a row-level mutex
  (`Cache::lock("notif-dispatch:{$eventRowId}")`) when channels
  fan out to async jobs. The synchronous baseline is sufficient
  for v8.0 since the in-app and email channels are both quick
  (the email channel queues to `Mail`, not waits for SMTP
  ack). When v8.x adds slow external channels (Discord webhook
  retry chains, etc.) revisit with strategy (b).

## Alternatives considered

1. **Use Laravel's built-in `Illuminate\Notifications` package
   instead of rolling a custom schema.** Rejected: the built-in
   `notifications` table is per-recipient only (no
   tenant-wide-event support), forces a polymorphic
   `notifiable_type` column we don't need (always `User`), and the
   per-channel preferences would still need a custom table. The
   built-in `Notifiable` trait is still used on `User` for the
   password-reset mail; this ADR doesn't displace it.
2. **WebSocket bell (Reverb) instead of polling.** Rejected for v8.0
   to keep the W1 scope tight. The schema doesn't change if we
   add Reverb later — only the FE event source flips from
   `setInterval(30s)` to a Reverb subscription.
3. **One row per (user, event, channel) instead of one row per
   (user, event) with a `channel_dispatch_log` array.** Rejected:
   the per-channel row layout would N-multiply the payload + audit
   storage for every dispatched event and force the bell to
   collapse N rows per logical notification into a single feed
   item. The accepted shape is one row per (user, event) with the
   `channel_dispatch_log` array tracking per-channel delivery
   outcomes — the dispatcher writes the row once and each channel
   adapter appends its own log entry under a per-row serialised
   contract (see Dispatcher section above).
