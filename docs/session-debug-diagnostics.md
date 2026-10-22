# Reading session debug diagnostics

Download the existing conversation debug JSON while the run is still open:
`GET /api/admin/conversations/{conversation}/debug-transcript`. The existing
administrator permissions and active-tenant boundary apply. Downloading does
not resume a run, dispatch a job, contact a provider, or probe a remote service.

The transcript remains `schema_version: 2`. All existing fields retain their
shape; persisted content passes through the shared credential/PII redactor.
New top-level and per-run `diagnostics` objects have their own
`schema_version: 1`. Older consumers can ignore them. The complete transcript
is not truncated to fit the diagnostic limits.

## Start with the pending phase

1. Find the relevant `agent_runs[]` entry and read its persisted `status`.
2. Read `diagnostics.open_spans`, including `phase`, `age_ms`, and technical
   `metadata`. These are starts whose ends have not been observed.
3. Read `waiting.observed_phase` and `reported_wait_for`/`reported_wait_reason`.
   The phase is the most recently started open work span; parallel branches can have
   several open spans, so inspect the full array.
4. Follow `research_links.children[].run_id` to independent research runs in
   the same export. Links require matching tenant, conversation and project,
   plus both sides of the persisted parent/child relationship. Branch events
   normally appear on the parent event stream.
5. Compare `last_activity_at` and `activity_age_ms` across downloads. Activity
   comes from span starts/ends, persisted events, and tool start/completion
   timestamps. Touching `agent_runs.updated_at` does not count as activity.
   `transport.*` spans and SSE heartbeats do not count as work activity or replace
   the pending work phase. `transport_last_activity` separately reports the
   latest observed transport span start/end and its age.

Common observations:

| Observation | What the record establishes |
| --- | --- |
| `queue.wait` open, `wait_for: worker_receipt` | Enqueue began; receipt was not recorded. Inspect the configured queue/worker externally. A stopped worker is not proven. |
| `worker.execute` or `run.execute` open | Execution began and no completion was recorded. Inspect the more specific open spans. |
| `kb.embedding` open | Embedding/cache generation began; it may involve a cache hit or a provider call. The span alone cannot tell which is still pending. |
| `kb.vector_query` / `kb.full_text_query` open | The local query began without an observed return. |
| `kb.rerank`, `kb.assessment_provider` or `provider.*` open | That operation/provider boundary began without an observed return. |
| `tool.execute` open | Use `metadata.tool_kind`/`tool_name` to identify the tool boundary. |
| `research.fanout`, `kb.research_fanout` or `research.child_wait` open | A fanout/handoff began; inspect its branch IDs and branch spans. |
| `research.process_dispatch` / `research.process_wait` open | A research process is being started or its result is awaited/decoded. `research_flow_id` correlates the task. |
| Failed span, `error_handled: true` | The caller handled an exception or an explicit failed research result. This can explain a fallback or a later partial result. |
| `error_kind: timeout` | A timeout exception class was observed. Silence or a long duration alone never sets this classification. |
| `status: cancelled` on a span | Cancellation was observed by the instrumented operation. A cancelled run may still have open spans until its process observes cancellation. |
| `terminal_event_not_recorded` | A terminal parent run has no matching persisted `run.completed`, `run.partial`, `run.failed` or `run.cancelled` event at capture time. |
| `open_spans_in_terminal_run` | The run is terminal but some observed starts have no observed end. This may be an interruption, concurrent cancellation, or missing instrumentation completion. |

`waiting.cause` remains `unknown`; `worker_liveness` is `not_probed`.
Neither event absence nor activity age establishes a root cause. There is no
process heartbeat. Reads are not an atomic database snapshot, so an export
taken during a transition can show a transient mismatch. Server-side logging
also cannot prove that a browser received the SSE event: compare the persisted
terminal event with client/network evidence when investigating delivery.

## Compare local and develop

`diagnostics.runtime_at_export` describes the process serving the download.
The `runtime` metadata recorded on execution spans describes the process that
actually executed the run. Compare these before assuming a worker has loaded
the current deployment/configuration.

The allowlisted runtime fields include release/environment, PHP/Laravel,
configuration-cache state, queue connection/driver/name and retry interval,
job timeout, cache/database drivers, planner mode, fanout/research settings and
relevant extension availability. Set `APP_RELEASE` to the build
identifier during the normal deployment process if desired. Missing release
information remains `null`; the exporter does not run Git or inspect arbitrary
environment variables. Existing configured timeouts are reported, not changed.

No credentials, arbitrary environment dump, hostnames, endpoint URLs, prompts,
retrieved documents or exception messages enter the new span metadata. Error
details are class/kind only. Existing transcript payloads still contain the
authorized conversation/evidence, with the shared redaction policy applied;
that policy is not a guarantee of complete anonymization.

## Schema and coverage limits

| Path relative to a run's `diagnostics` | Meaning |
| --- | --- |
| `schema_version`, `captured_at` | Diagnostic schema and observation timestamp. |
| `availability` | `recorded`, `no_spans_recorded`, or `storage_unavailable`. Legacy runs cannot be reconstructed retroactively. |
| `last_activity_at`, `activity_age_ms`, `last_activity` | Latest observed timestamp, age and event/span/tool reference; `null` when unavailable. |
| `transport_last_activity` | Latest transport span observation and age, kept separate from actual work. Server emission is not a client receipt acknowledgment. |
| `waiting` | Persisted run status, most recent open phase/reference, operation-reported wait fields, unknown cause and unprobed worker liveness. |
| `spans[]` | `id`, `parent_span_id`, `phase`, `status`, `started_at`, `ended_at`, `duration_ms`, `metadata`. Status is `started`, `completed`, `failed` or `cancelled`. |
| `open_spans[]` | Open entries from `spans`, with current `age_ms`. |
| `terminal_event` | Whether a terminal event is expected/recorded, its type, and whether research events are forwarded to a parent. |
| `research_links` | Verified `parent`, `children`, and `children_truncated`. |
| `warnings` | Missing terminal observation, open spans in a terminal run, unavailable storage or capacity reached. |
| `limits` | `max_spans: 256`, `max_metadata_bytes: 2048`, export `spans_truncated`, and `span_capacity_reached`. |
| `interpretation` | Reminder of the observation limits. |

Only the first 256 spans per run are persisted. At capacity, later phases may
be unobserved; `span_capacity_reached` makes that limit explicit. Span metadata
is allowlisted and bounded to 2,048 bytes; export applies the shared sanitizer
again and can replace oversized metadata with a truncation summary. Span IDs,
run IDs and timestamps retain their technical identity. Parent-span links are
available where explicitly instrumented; absent links do not imply independent
execution. Diagnostics fail open and never choose a provider, change timeout or
cancellation policy, or alter the operation's result.

Only eight `transport.event_stream` spans per run are retained so reconnects
cannot consume the whole trace. A completed operation means its callback
returned; it does not prove evidence sufficiency. Read existing stop reasons
and failed child spans as well. Provider/model metadata is filled from actual
responses where available; a pending inherited model may be unknown. Internal
HTTP retries inside the decision provider share one `provider.decision` span.

The new table is installed by the normal application migration step; no local
or remote operational database was migrated to test this change. Apply
`2026_10_22_000001_create_agent_diagnostic_spans_table.php` and restart long-lived
workers with the usual deployment process. Without the table, searches retain
their behavior and exports report unavailable diagnostic storage. Persistence
adds bounded local database reads/writes at phase boundaries, with no polling
writes, AI calls or network probes. Production database overhead has not been
benchmarked.

## Sanitized example

[`examples/session-debug-pending-kb.json`](examples/session-debug-pending-kb.json)
is a synthetic excerpt of an active export; legacy transcript fields and
sanitizer bookkeeping are omitted for readability. No real user, query,
document or provider content appears in it.

Its queue span ended at worker receipt, and its local vector-query span began
but has not ended. The query age is 45 seconds. This localizes the last observed
boundary; it does **not** prove a database deadlock, worker death or timeout.
The worker's release differs from the export process's release, which is a
concrete configuration/deployment comparison to investigate. The JSON itself
contains no comments; these paragraphs are its annotations.

## Deterministic verification

`vendor/bin/phpunit tests/Feature/Admin/ConversationDebugTranscriptTest.php`
covers active exports, pending KB, success/failure/cancellation, missing final
observations, verified research handoff, tenant isolation, redaction of nested
payloads/errors, retained technical IDs, metadata/span bounds and unchanged
legacy transcript size. Core instrumentation tests cover the operations and
queue boundaries separately. These tests use local fixtures/fakes, with no
paid AI calls or diagnostic network probes.
