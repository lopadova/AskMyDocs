# Runbook — migrating a live deployment to laravel-flow v2

This is a **one-way, data-bearing** migration. The package converts
`flow_steps` into `flow_run_nodes` and then **drops `flow_steps`**; its `down()`
is a deliberate no-op. `php artisan migrate:rollback` will not give the old
table back. The only recovery from a bad conversion is restore-from-dump, so
the dump in step 3 is not optional.

Read the design rationale in the
[Flow orchestration doc-site page](https://padosoft.mintlify.app/flow-orchestration)
before running this. This runbook assumes PostgreSQL, where DDL is
transactional and Laravel wraps each migration — add, backfill, verify and
tighten therefore commit or roll back as one unit.

## 0. Does this apply to you?

If `LARAVEL_FLOW_PERSISTENCE_ENABLED` has always been `false` on this
deployment, there is no history to convert. The new tables are created empty,
the conversion migration is a guarded no-op, and you can deploy normally
without the rest of this runbook. Confirm before you skip it:

```sql
SELECT to_regclass('public.flow_steps')  AS flow_steps,
       to_regclass('public.flow_runs')   AS flow_runs;
```

`flow_steps` NULL → nothing to convert. Both non-NULL → continue.

## 1. Pre-flight gate queries

Run these **before** scheduling the window. They decide how long it takes and
whether it can succeed at all.

### 1a. Volume — sizes the window

```sql
SELECT
    (SELECT count(*) FROM flow_runs)           AS runs,
    (SELECT count(*) FROM flow_steps)          AS steps,
    (SELECT count(*) FROM flow_audit)          AS audit,
    (SELECT count(*) FROM flow_approvals)      AS approvals,
    (SELECT count(*) FROM flow_webhook_outbox) AS outbox;
```

The conversion copies `flow_steps` in chunks of 500 and the backfill walks
`flow_runs` in chunks of 500. Both are single-threaded. Use the step count to
estimate; a few hundred thousand rows is minutes, tens of millions is a
different conversation and should be rehearsed on a restored copy first.

### 1b. Tenant cardinality — the unknown that sizes everything else

```sql
SELECT tenant_id, count(*) AS runs
FROM flow_runs
GROUP BY tenant_id
ORDER BY runs DESC;
```

Two things to read off this:

- **A single tenant, and it is `default`.** The backfill is uniform and the
  blast radius of a mis-stamp is nil, because there is only one tenant to
  confuse. This is the easy case.
- **More than one tenant.** The backfill is the only thing standing between
  historical nodes and cross-tenant visibility. Record the exact per-tenant
  counts now: step 5 verifies the post-migration node distribution against
  them.

```sql
SELECT count(DISTINCT tenant_id) AS tenants,
       count(*) FILTER (WHERE tenant_id IS NULL) AS untenanted_runs
FROM flow_runs;
```

`untenanted_runs` **must be 0**. A run with no tenant produces nodes with no
tenant, and step 4's tighten will abort the migration. Fix these first — they
predate the v2 work and mean the v4.2 tenant migration did not fully apply.

### 1c. Orphan steps — the one condition that stops the migration mid-window

```sql
SELECT count(*) AS orphan_steps
FROM flow_steps s
LEFT JOIN flow_runs r ON r.id = s.run_id
WHERE r.id IS NULL;
```

**Expect 0, and confirm it anyway.** `flow_steps.run_id` carries a foreign key
to `flow_runs.id` with `ON DELETE CASCADE`, so on a database where that
constraint has always been enforced an orphan cannot exist. The check is here
because the constraint is not guaranteed to have been enforced on *this*
database — a restore taken with constraints disabled, a table recreated out of
order, or a manual repair can all leave rows the FK would have refused.

If it is non-zero, this is the condition that stops the migration **after** it
has started. `flow_run_nodes` carries the same FK, and PostgreSQL does not
suppress a foreign-key violation for `INSERT ... ON CONFLICT DO NOTHING`, so
the conversion itself fails; the host tenant migration's explicit throw is the
second net behind it. Either way the transaction rolls back and you are left
mid-window with a half-deployed release.

Decide before the window whether those rows are worth keeping — they are step
records for runs that no longer exist, so usually they are not — and record
what you remove:

```sql
-- inspect first
SELECT s.run_id, count(*) FROM flow_steps s
LEFT JOIN flow_runs r ON r.id = s.run_id
WHERE r.id IS NULL GROUP BY s.run_id;

-- then, once reviewed
DELETE FROM flow_steps s
WHERE NOT EXISTS (SELECT 1 FROM flow_runs r WHERE r.id = s.run_id);
```

<!-- Duplicate (run_id, step_name) is deliberately NOT checked: flow_steps
declares unique(run_id, step_name), so the conversion's insertOrIgnore has
nothing to silently drop. Adding a check for an impossible condition trains
operators to skim the list. -->

### 1d. Work in flight — what the window interrupts

```sql
SELECT status, count(*) FROM flow_runs
WHERE status IN ('pending', 'running', 'paused')
GROUP BY status;

SELECT run_id, step_name, expires_at
FROM flow_approvals
WHERE consumed_at IS NULL
ORDER BY expires_at;
```

`running` rows are runs whose worker you are about to stop; they resume from
their persisted state after step 6 and need no special handling, but knowing
the count tells you what "the queue drained" should look like.

Pending approvals matter more. An operator holding a promotion token cannot use
it while the app is down, and a token whose `expires_at` falls inside the window
is **dead on the other side** — the promotion has to be re-requested from the
draft. If the list is non-empty and any expiry is close, either clear those
approvals before starting or tell the holders the window will invalidate them.

## 2. Freeze the writers

Flow rows are written by the scheduler, by queue workers and by HTTP requests
that run a flow inline. All three have to stop; the migration converts a table
that is being written to otherwise.

**Stop the scheduler** — however this deployment invokes it: a crontab entry, a
supervisor program, a platform scheduler. Then confirm nothing survives:

```bash
pgrep -af 'schedule:run|schedule:work' || echo 'scheduler clear'
```

**Drain the workers.** Let in-flight jobs finish rather than killing them;
`queue:restart` signals every worker to exit after its current job.

```bash
php artisan queue:restart
```

Wait for it, then confirm the queue is idle and no worker is alive:

```bash
php artisan queue:monitor default           # prints the current queue size
pgrep -af 'queue:work|queue:listen' || echo 'workers drained'
```

A non-empty queue at this point is fine as long as nothing is consuming it —
those jobs run after step 6. A live worker is not fine.

**Put the app in maintenance mode** so no HTTP request starts an inline flow:

```bash
php artisan down --retry=60
```

**Do not disable persistence during the window.** Turning
`LARAVEL_FLOW_PERSISTENCE_ENABLED` off does not make the conversion safer — it
converts the same rows either way — but it does make step 6 unable to observe
that new runs are being recorded, and a deploy that forgets to turn it back on
stops recording every run with no error anywhere. Freeze the writers, not the
feature.

## 3. Dump the flow tables

Five tables on the v1 shape. Take them in one consistent snapshot:

```bash
pg_dump "$DATABASE_URL" \
  --format=custom \
  --file="flow-pre-v2-$(date +%Y%m%d%H%M).dump" \
  --table=public.flow_runs \
  --table=public.flow_steps \
  --table=public.flow_audit \
  --table=public.flow_approvals \
  --table=public.flow_webhook_outbox
```

Verify the dump is readable and contains all five before continuing — a dump
you have not listed is a dump you do not have:

```bash
pg_restore --list flow-pre-v2-*.dump | grep -c 'TABLE DATA public.flow_'
# expect 5
```

## 4. Deploy the code and migrate

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
```

Ten migrations run, in this order:

| Migration | Effect |
|---|---|
| `2026_07_08_000005_create_flow_definitions_table` | new, stays empty |
| `2026_07_08_000006_add_definition_version_to_laravel_flow_runs` | column |
| `2026_07_09_000007_create_flow_run_nodes_table` | new target table |
| `2026_07_09_000008_add_graph_columns_to_laravel_flow_runs` | columns |
| `2026_07_09_000009_migrate_flow_steps_to_run_nodes` | **copies, then drops `flow_steps`** |
| `2026_07_09_000010_create_flow_node_children_table` | new, stays empty |
| `2026_07_09_000011_add_graph_to_laravel_flow_runs` | column |
| `2026_07_09_000012_create_flow_node_cache_table` | new, stays empty |
| `2026_08_23_000001_add_subject_to_flow_runs_table` | column — **required**, written on every insert |
| `2026_10_02_000008_add_tenant_id_to_flow_run_nodes` | **host**: add nullable, backfill from runs, verify, tighten |

If the last one aborts with *"N flow_run_nodes row(s) have no tenant after the
backfill"*, the migration rolled back and the database is consistent. That is
step 1c's condition appearing after the fact: resolve the orphans and re-run.
The conversion is re-runnable — it copies with `insertOrIgnore` keyed on
`(run_id, node_id)` — but `flow_steps` may already be gone, in which case
restore it from the step-3 dump before retrying.

## 5. Verify — the four queries

### 5a. The conversion is complete

```sql
SELECT
    (SELECT count(*) FROM flow_run_nodes) AS nodes,
    (SELECT count(*) FROM flow_run_nodes WHERE node_type = 'legacy.step') AS converted;
```

`converted` must equal the `steps` count recorded in step 1a (minus anything
you deliberately deleted in 1c/1d). `nodes` equals `converted` immediately
after the migration and grows once workers resume.

### 5b. No node is untenanted

```sql
SELECT count(*) AS untenanted_nodes
FROM flow_run_nodes
WHERE tenant_id IS NULL OR tenant_id = '';
```

**Must be 0.** The migration's own tighten already guarantees this or it would
have thrown, so a non-zero result here means something wrote rows after the
migration and before the check — investigate before resuming workers.

### 5c. Every node's tenant matches its run's tenant

This is the invariant the whole design rests on: *a node is visible exactly
when its run is visible*.

```sql
SELECT count(*) AS mismatched
FROM flow_run_nodes n
JOIN flow_runs r ON r.id = n.run_id
WHERE n.tenant_id IS DISTINCT FROM r.tenant_id;
```

**Must be 0.**

### 5d. The per-tenant distribution matches expectation

Compare against the per-tenant run counts recorded in step 1b:

```sql
SELECT n.tenant_id, count(*) AS nodes, count(DISTINCT n.run_id) AS runs
FROM flow_run_nodes n
GROUP BY n.tenant_id
ORDER BY nodes DESC;
```

The `runs` column here should be a subset of step 1b's per-tenant run counts —
a run with no steps recorded contributes none. A tenant appearing here that was
**not** in 1b, or a `default` row that is larger than 1b said it should be, is
the mis-stamp this whole procedure exists to prevent: stop, do not resume
workers, restore from the step-3 dump.

Also confirm the three untenanted v2 tables are empty, as the architecture test
assumes:

```sql
SELECT
    (SELECT count(*) FROM flow_definitions)   AS definitions,
    (SELECT count(*) FROM flow_node_children) AS node_children,
    (SELECT count(*) FROM flow_node_cache)    AS node_cache;
```

All three must be 0. The host registers its nine definitions from code and
never reaches the graph executor.

## 6. Resume — workers last

Order matters. Bring the app back before the workers so a queued job cannot
execute a flow against a half-verified schema.

```bash
php artisan config:cache && php artisan route:cache
php artisan up
```

Smoke-test one read path before any worker starts. With
`FLOW_ADMIN_ENABLED=true`, `/admin/flows/api/live` must answer with KPIs; with
it false, the whole prefix must answer 404 — both are correct, a 500 is not.

Then, and only then:

```bash
# restart the queue workers (supervisor / platform process manager)
# and re-enable the scheduler
```

Confirm new runs are being recorded — this is the check that the "do not
disable persistence" rule in step 2 exists to keep available:

```sql
SELECT count(*) FROM flow_runs WHERE created_at > now() - interval '10 minutes';
```

## 7. If it went wrong

The conversion is forward-only, so there is no `migrate:rollback` path.

1. `php artisan down`, stop workers and the scheduler again.
2. Drop the v2 tables and restore the five v1 tables from the step-3 dump:

   ```bash
   pg_restore --dbname "$DATABASE_URL" --clean --if-exists \
     --table=flow_runs --table=flow_steps --table=flow_audit \
     --table=flow_approvals --table=flow_webhook_outbox \
     flow-pre-v2-<stamp>.dump
   ```

3. Roll the application code back to the previous release (v1 line), so the
   restored schema matches the deployed code.
4. Delete the ten v2 rows from the `migrations` table so a later re-attempt
   re-runs them.

Flow history is operational telemetry, not the knowledge base: even a total
loss of the `flow_*` tables does not lose a document, a chunk, a canonical node
or an audit row. If restoring is more expensive than the history is worth, an
acceptable last resort is to accept the loss and continue on v2 with an empty
history — but make that a decision someone signs, not a default.

## Checklist

- [ ] 1a volume recorded
- [ ] 1b per-tenant run counts recorded; `untenanted_runs` = 0
- [ ] 1c `orphan_steps` = 0
- [ ] 1d in-flight runs counted; pending approvals reviewed for expiry inside the window
- [ ] Scheduler stopped, workers drained, app in maintenance mode
- [ ] Persistence flag left **untouched**
- [ ] `pg_dump` taken and listed (5 tables)
- [ ] `migrate --force` completed without the tenant abort
- [ ] 5a converted count matches 1a
- [ ] 5b untenanted nodes = 0
- [ ] 5c node/run tenant mismatches = 0
- [ ] 5d per-tenant distribution matches 1b; three v2 tables empty
- [ ] App up, read path smoke-tested, then workers and scheduler resumed
- [ ] New runs appearing in `flow_runs`
