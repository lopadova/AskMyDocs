# Scheduled IMAP sync: queue admission and recovery

The minute scheduler checks active installations. A completed installation is
eligible again after `connectors.default_sync_cadence_minutes` (15 by default,
with per-connector overrides). The package's effective date filter is the later
of the last completed sync and the configured rolling window. Autry currently
uses `date_window_days=10`.

During an incomplete scan, the safe contiguous UID checkpoint advances while the
last-completed timestamp stays unchanged. Clean capped batches may continue
immediately. A runtime-budget interruption or busy connection schedules a fresh
payload after `connectors.imap.mailbox_lock.requeue_after_seconds` (60 by default).
This continuation is independent of the 15-minute completed-sync cadence.

`dispatchFor()` reserves at most one pending request per tenant/installation.
The queue payload carries an owner token. The reservation remains held while
mailbox overlap middleware defers the request, and is released when handling
starts or the payload fails. Cache releases are owner-checked, so an old delivery
cannot unlock a newer pending request. A TTL exceeds the payload retry window
plus the job timeout and a grace period; unavailable lock stores retain the
existing fallback. The physical mailbox mutex still serializes actual IMAP
connections across installations and backfill.

A continuation uses a fresh payload carrying its own reservation and deadline.
Releasing the old queue payload would reuse its serialized owner/retry deadline.
A payload expiring after benign deferrals must not mark an otherwise active IMAP
account errored. Real connector errors and hard timeouts still surface normally
(`TimeoutExceededException` inherits `MaxAttemptsExceededException` and is
explicitly excluded from the benign expiry path).

For an existing errored account, inspect its exact tenant/installation/error and
queue first. Deploy the correction before a guarded operator re-arm. Preserve
credentials, `last_sync_at`, UID state and source documents. Let the next minute
sweep dispatch automatically, then verify nonzero processing and advancing UID
checkpoints. Rows reporting success with zero work while the account is errored
are not proof that mail retrieval works.
