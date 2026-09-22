# Local queue and scheduler services

`install-local.sh` installs three user-scoped `launchd` services for this
checkout: the `agent,kb-ingest,default` worker, the dedicated `connectors`
worker, and Laravel's persistent scheduler. The templates keep paths as tokens
so the repository never stores a developer's home directory or secrets.

Run once after changing worker topology:

```zsh
./ops/launchd/install-local.sh
```

Check a service with `launchctl print gui/$(id -u)/com.askmydocsdev.queue-core`.
The standard and error logs are under `storage/logs/launchd-*.log`.
