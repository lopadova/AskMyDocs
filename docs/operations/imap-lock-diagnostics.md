# IMAP lock diagnostics

Run on the affected deployment, with the same configuration as the web application
and workers. Tinker is not required:

```bash
php artisan connectors:imap:diagnose prima-demo
```

With only the exact tenant slug, the command selects its sole IMAP installation,
even if there is no backfill left. If several IMAP installations exist, it lists
their IDs, labels, statuses and exact commands to run, without choosing an account
or reading any locks. If none exist, it reports that explicitly. Ambiguous/missing
targets exit non-zero.

To inspect a specific backfill, add its id:

```bash
php artisan connectors:imap:diagnose prima-demo 6
```

A missing backfill also lists the available installation IDs; it never silently
switches to a different target. To inspect an IMAP installation directly:

```bash
php artisan connectors:imap:diagnose prima-demo --installation=123
```

Replace `123` with the actual installation id. This mode also reports the latest
backfill for that installation, if any. Both lookups are tenant-scoped.

The command reports the backfill status/heartbeat, configured queue connection and
driver (which need not match the cache driver), mailbox serialization settings,
and two separate locks:

- `mailbox_lock`: the physical mailbox lock used by sync, backfill and folder requests.
- `queue_overlap_lock`: the `laravel-queue-overlap:` lock used by queue middleware.

TTL inspection uses the Redis cache store's **lock connection** and cache prefix,
not the queue connection. TTL values mean:

- `>= 0`: lock present; seconds remaining until expiry (`0` means less than a second).
- `-2`: no lock at the instant of the read.
- `-1`: lock present without expiry.

This is a snapshot, not a worker health check. A present lock does not prove that
its owner is alive, and the two reads are not atomic. Running again can reveal
whether TTLs decrease or have been refreshed/reacquired, but cannot identify the
process. A missing lock does not establish that IMAP authentication works.

The command never acquires/releases locks, waits for a lock, opens an IMAP
connection, dispatches/deletes jobs or changes backfill data. It omits credentials,
Redis URLs and lock owner tokens. Unsupported cache stores, Redis read failures
and invalid targets exit non-zero and report an unknown lock state rather than
claiming locks are free. Exit zero means inspection succeeded, not that locks are
absent or an import is healthy.

## `BAD Could not parse command` during `search_first_uid`

Webklex 6.2's query builder quotes UID ranges/lists, producing `UID "1:1000"`
or `UID "1,2,3"`. IMAP requires an unquoted sequence-set (`UID 1:1000` /
`UID 1,2,3`), as defined in [RFC 3501 section 9](https://www.rfc-editor.org/rfc/rfc3501#section-9).
The host backfill client now uses a raw numeric UID criterion for both discovery
and bulk fetch searches, while leaving date formatting/escaping unchanged.
Bulk UID values are validated before folder I/O; arbitrary strings cannot become
raw commands. Regression tests use the installed Webklex query builder and capture
its protocol request rather than mocking the criteria methods.

This syntax error is not fixed by deleting jobs, clearing Redis or changing IMAP
credentials. Deploy the corrected application code to the queue workers too.
Existing retry attempts can then use the fix; a campaign already marked failed
can be resumed through the full-history import action, preserving its checkpoints.
Verify that discovery completes and the campaign progresses beyond `discovering`.

## Invalid `INTERNALDATE` during discovery

Webklex 6.2 can truncate the quoted date in a Gmail response shaped like
`(UID 123 INTERNALDATE "07-Sep-2026 21:23:57 +0000")`, leaving only
`"07-Sep-2026` for the application to parse. The backfill client recovers the
complete date from the same successful, metadata-only FETCH response, matching
the requested UID. It preserves the time and numeric offset and rejects partial
or invalid dates instead of inventing midnight or rolling over calendar dates.
Regression tests replay wire responses through the installed Webklex decoder.

This is a parsing failure, not an authentication or Redis configuration problem.
The worker deployment must include the fix; no credential rotation, queue purge
or backfill reset is required by this correction.

## Non-selectable mailbox containers

Backfill discovery filters LIST entries marked `\Noselect` (for example Gmail's
`[Gmail]` container) before taking mailbox snapshots. It keeps their selectable
children and decoded UTF-8 paths, including when no folder whitelist is set.
This is a server-attribute check, not a hard-coded Gmail name exclusion. A
selectable parent with children remains importable. LIST/authentication failures
still fail discovery explicitly instead of being treated as an empty mailbox.
This host-side filter applies to backfills; the package's folder-picker listing
and incremental-sync implementation are unchanged.

## Independent download windows and phase logs

Each `ImapBackfillWindow` owns its half-open date range `[start, end)`, mailbox,
snapshot UIDVALIDITY/max UID, last confirmed UID, attempts, heartbeat and error.
A completed window stays completed when another window is retried. A terminal
failure is recorded on that window; the pump continues the remaining windows and
settles the campaign after all windows have reached a terminal state. Resuming a
failed campaign retains completed windows and saved UID checkpoints.

Application logs prefixed `[imap-download]` correlate each batch with a fresh
`diagnostic_id`, installation/tenant/window IDs, mailbox hash, date range and UID
checkpoint. Phases cover client creation, mailbox selection, UID search, size
metadata, body download, local persistence and close. They record elapsed time,
UIDs, byte/attachment counts, runtime memory and exception chains. The queue job
logs `window checkpoint saved` only after the database transaction commits. Mail
bodies, subjects, attachment contents and authentication tokens are not fields in
these events. If both download and close fail, the original download error is
retained and the close failure receives its own phase event.

Backfill fetch planning uses `RFC822.SIZE` metadata before downloading bodies.
`CONNECTOR_IMAP_BACKFILL_FETCH_SIZE` limits messages per fetch (default 5), and
`CONNECTOR_IMAP_BACKFILL_FETCH_MAX_BYTES` limits their combined raw size (default
8 MiB). Oversized messages are fetched alone; clients without size metadata also
fetch one message at a time. Parsed message arrays are released between chunks.
Raw size is a planning budget, not a strict PHP memory cap: MIME parsing needs
additional memory, and one oversized message can exceed the budget. The existing
per-job message cap and worker timeout remain in effect.

The host read-only protocol opens folders with `EXAMINE` and downloads with
`BODY.PEEK`, rejecting remote mutations. Recent successful wire reads avoid
redundant library NOOP checks for 15 seconds; idle connections still receive a
fresh check and failed reads invalidate the shortcut. The library's immediate
flag reread after its local Seen-restoration no-op can reuse the flags just read;
later independent reads always reach the server. Metadata reads explicitly open
the folder, because STATUS alone does not select it.

## Worker restarts or memory exhaustion on one UID

An IMAP message can be tens of MiB while parsing uses several times its size.
Base64 payloads, raw wire response lines, multipart splitting and decoded
attachments can coexist in memory. An `Allowed memory size ... exhausted` error
describes the PHP process, not the size of an attachment. Reducing the batch to
one message cannot remove copies of that single message.

The host protocol now reads counted literals in 64 KiB chunks and excludes their
payload from the raw response journal. It preserves the parsed literal's bytes,
including binary data, and rejects an interrupted literal. For messages above
8 MiB, both backfill and incremental sync use the same MIME parser: it scans
boundaries by offsets, keeps Webklex's header/charset/attachment decoders, releases
the original body before decoding and collects parsed Message/Attachment cycles
after mapping the result. Small messages retain the existing package path.
Missing multipart closing boundaries fail explicitly; they do not confirm a UID
with a silently omitted final attachment. The size threshold selects a parser;
it does not impose an attachment-size limit or guarantee a fixed memory ceiling.

Every phase logs `phase started` before its operation, with current/peak PHP
memory and `php_memory_limit`. `fetch_messages` also logs requested UIDs and their
combined `rfc822_bytes`. If a process is killed before PHP can catch the error,
correlate the last start without a completion/failure with platform worker logs
and restart events. `RFC822.SIZE` is supplied by the server and may differ from
the transferred MIME size. PHP's memory limit and the container's physical RAM
are separate constraints.

For Laravel Cloud managed queues, inspect **Cloud Monitoring / Queues** and the
worker's platform logs. An empty application `failed_jobs` table does not prove
the Cloud queue has no failed jobs. Ensure the worker deployment contains the
fix, then verify that the affected window advances beyond its saved UID. If the
campaign has already reached a terminal failure, resume it through the existing
full-history action, retaining its windows and checkpoints. Do not reset the
campaign or purge queues as a memory fix. A killed process can leave a mailbox
lock until its configured TTL expires; a lock's presence alone does not authorize
force-unlocking a possibly active session.

For a download-only validation, use an isolated local verification journal and
sink rather than the ingestion contract or production queues. Record the selected
UIDs under `(mailbox, UIDVALIDITY, UID)`, hash-check bodies and attachments after
writing, confirm each message transactionally, and rerun the same sample to check
that no confirmed message is fetched again. Verify server flags before and after
the test. Keep diagnostic artifacts under ignored storage, outside Git.
