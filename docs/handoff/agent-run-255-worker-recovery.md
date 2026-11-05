# Run 255: interrupted worker and terminal tool state

Environment: Surface / Ask My Docs / develop, tenant/project `autry`.
Run UUID: `ff9dfdfe-1e12-4c6c-a75a-b7102116928a`, conversation 116.
All incident times below are UTC on 29 October 2026.

## Established evidence

The user export `chat-debug-116-20261029-174530.json` records:

| Time | Observation |
| --- | --- |
| 17:42:18 | Run enqueued |
| 17:42:19 | Worker attempt 1 received it; configured job timeout 120 s |
| 17:42:48 | Combined initial planning finished after 28.444 s |
| 17:42:49 | One wave dispatched five isolated processes: two API lookups and three KB searches |
| 17:42:52–53 | Exact KB searches found 21, 23 and 23 candidates; embedding spans started |
| 17:45:19 | Queue failure recorded `MaxAttemptsExceededException`; run became failed |

The Cloud application log was also inspected and contains, at
**17:45:19.204**, `App\Jobs\ExecuteAgentRunJob has been attempted too many times.`
The trace enters Laravel's worker max-attempts preflight. This establishes
redelivery/exhaustion, not the reason the first worker stopped making progress.
The user confirmed the latest successful deployment is `8003ca7`, which includes
the direct-PHP-argv process termination fix. The export's release field is null,
so it cannot independently identify the worker's loaded revision.

There is no completed wave, process-wait result or provider response in this
export. Five executions remain `running`, the wave remains `running`, and
`wave_dispatching` remains true even after `run.failed`. Recorded physical calls
are zero because the parent adopted no observations; that is not proof that no
request reached an external service.

Searches for `Killed` and `memory` in the inspected Cloud application logs
returned no entries. They do not establish the absence of a container OOM or
another infrastructure termination. Neither an embedding outage, a Gescat
failure nor memory exhaustion is established as the first interruption's cause.
The 180-second interval alone is not proof of a particular worker timeout.

Further inspection requires the default worker's logs and metrics for
**17:41–17:46 UTC**, including worker/Cloud-agent exits, restart reasons and
container memory. At the latest attempt browser access was disabled in Codex
Work settings. Normal login at `id.laravel.com` is already authorized by the user;
no new access token or permission change is needed.

The existing Cloud CLI credential was also tried against `Ask My Docs` / `develop`.
Its application list belongs to a different organization and does not contain
Ask My Docs, so it could not retrieve this environment's worker logs.

## Bounded embedding request for exact searches

The Laravel AI SDK's default embedding request timeout is 30 seconds. The
interactive worker reserves time for synthesis, so a research wave can have
less than 30 seconds left. A semantic request waiting for embeddings can thus
consume the entire wave after exact candidates have already been found; when
the process is stopped, those candidates are lost with it.

When a KB wave has exact candidates, its SDK embedding request now has a
configurable 10-second timeout (`AGENT_EXACT_EMBEDDING_TIMEOUT_SECONDS`). If
the request fails, the existing partial-retrieval path reads and returns the
authorized exact sources. Searches without exact candidates and embedding calls
outside this scoped operation keep the SDK's existing timeout. This bounds the
SDK HTTP request, not database access or the complete wave, and cannot guarantee
completion if the wave has very little time left. The run 255 export does not
establish whether an embedding provider was slow or whether the worker stopped
for a different reason.

## Corrected application defect

Previously terminal run publication did not reconcile open tool executions or
wave state. Queue-level failure bypasses the ordinary per-tool result path, so
those rows could remain running indefinitely.

Terminal event publication now closes queued/running/pending executions under
the run-row lock, records completion/error metadata, closes open waves and clears
the dispatch barrier. Each interrupted execution emits `tool.failed` before the
terminal event in the same transaction. Already completed siblings and saved
answers are preserved. Unknown physical outcomes are explicitly marked unknown;
cleanup does not manufacture successful results or alter request counters.

Result adoption takes the same run lock and checks that the run is active and
the execution still running. Late workers also reject terminal runs through
`AgentRunControl::ensureActive()`.

## Repairing the historical run after deployment

The following operator command is scoped to the explicit tenant and UUID,
rejects active/missing runs and is idempotent. It uses the same finalizer, emits
only missing tool failure events, and does not replay jobs or HTTP requests:

```sh
php artisan agent:reconcile-terminal-run ff9dfdfe-1e12-4c6c-a75a-b7102116928a --tenant=autry
```

It requires this code to be deployed first. This task has not pushed or deployed
the change, repaired run 255 online, or verified a new live demo run. Do not label
the demo ready until the first interruption is explained and a fresh document +
API run completes in the development environment.

## Verification

The existing Agent feature/unit suites and API continuation suite passed
**424 tests / 2,411 assertions** before the repair command was added. They cover
real subprocess overlap, deadlines, no late writes and mixed KB/API coordination.
The new lifecycle regressions cover five interrupted tools, event ordering,
cancellation, successful siblings, saved partial answers, tenant isolation,
active handoffs and rejection of stale workers. The operator-command regression
also checks mandatory tenant scope, refusal of active runs, repeated repair and
absence of enqueued work or network requests.

After command registration, the lifecycle, handler, coordinator, API continuation
and tenant-scope checks passed on PHP **8.5.12**: **40 tests / 352 assertions**.
The exact search, SDK provider and real subprocess timeout regressions passed
on PHP **8.5.12**: **36 tests / 159 assertions** after the embedding limit change.
