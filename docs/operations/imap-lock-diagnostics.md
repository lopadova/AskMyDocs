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
