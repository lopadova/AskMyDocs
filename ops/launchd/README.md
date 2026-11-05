# Local queue and scheduler services

`install-local.sh` installs user-scoped `launchd` services for this checkout:
the `agent,kb-ingest,default` worker, dedicated `connectors` and `freshdesk`
workers, two email-triage workers, and Laravel's persistent scheduler. The
templates keep paths as tokens so the repository never stores a developer's
home directory or secrets.

Freshdesk uses `freshdesk/freshdesk` to start a sync, run import batches and
generate case studies, and `redis/kb-ingest` for document ingestion. Its work
can proceed independently of the other connectors' backlog. The dedicated
Freshdesk connection has a 660-second reservation; its worker allows the
package's 600-second jobs to finish before retrying.

Run once after changing worker topology:

```zsh
./ops/launchd/install-local.sh
```

Check a service with `launchctl print gui/$(id -u)/com.askmydocsdev.queue-core`.
The standard and error logs are under `storage/logs/launchd-*.log`.
