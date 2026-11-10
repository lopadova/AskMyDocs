# Rolling back a release

Use this guide when a release misbehaves after the canary phase or in full
production. The decision belongs to the incident commander or the release
engineer on duty.

## Steps

1. Announce the rollback in the release channel.
2. Run `nwctl deploy rollback --to <tag>` with the last known good tag.
3. Wait for the health checks to turn green on all nodes.
4. Verify the error rate on the canary dashboard.

## Where the previous version lives

Every node keeps the previous build under `/srv/nw/releases/previous`. The
rollback switches the current symlink to that directory, so no artifact is
downloaded again.

## Timing

A full rollback takes under 4 minutes for the stateless services.

## Database migrations

Migrations are not reverted automatically. If the release included a
migration, ask the database owner whether the new schema is backward
compatible before rolling back.

## Afterwards

Open a ticket with the reason for the rollback and attach the failing build
number and the first error log lines.
