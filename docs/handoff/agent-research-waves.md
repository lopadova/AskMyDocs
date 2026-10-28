# Agent research waves

Research turns use one planner across documents, API routes and MCP connections.
The interpreter resolves the request first; the planner then schedules all relevant
independent actions whose inputs are available. No recursive document investigation
runs before the first plan, and multi-subquestion research does not spawn autonomous
planner branches.

Each wave follows this barrier:

1. Validate dependencies and reserve the shared logical/physical budget.
2. Register executions and start independent tasks in isolated PHP processes.
3. Collect all outcomes, including individual errors and timeouts.
4. Merge evidence and counters in the parent. Each observation and its execution
   checkpoint commit together.
5. Invoke the common planner to assess relevance, conflicting evidence and missing
   facts, then answer or schedule the next wave. Dependencies on results from the
   submitted plan are deferred until that evaluation.

Document workers perform one authorized retrieval, without an assessor or local
replanning loop. The configured retrieval-profile requirement remains enforced
for document search; a missing profile does not prevent sibling live-source calls.
Evidence is reauthorized before each common decision and final synthesis retains
the existing grounding checks. Context-only, provenance and clarification turns
retain their existing handling.

## Limits and diagnostics

`AGENT_WAVES_ENABLED` defaults to `true`; disabling it restores the previous
collection path. `AGENT_WAVE_CONCURRENCY` defaults to 10. If a wave contains more
ready actions, execution uses bounded batches and still performs only one common
evaluation after all batches. `AGENT_MAX_ACTIONS_PER_PLAN` defaults to 20.

`AGENT_WAVE_TIMEOUT_SECONDS` defaults to 45 and limits the entire wave, including
all batches. The parent further caps this against the worker deadline and answer
reserve. Successful siblings survive another task's failure or timeout. A task
whose physical outcome cannot be observed is charged its allocated quota
conservatively. Logical and physical limits remain shared; workers use detached
counters and disjoint physical allowances. Budget confirmation is exposed after
allowed siblings have been collected.

Events include `wave.started`, `wave.completed` and per-tool outcomes. Durable
`result_json.waves` records wave membership and deferred actions. Debug exports
include whether waves are enabled, concurrency and timeout configuration.

## Asynchronous API tools and interrupted workers

Composed API tools register ownership transactionally before dispatching their
graph, so cancellation can find every pending child even while other wave tasks
are still running. Their callbacks stay deferred until the parent has collected
its synchronous observations and atomically activated the entire pending set.
Only the final sibling can wake the common planner. Duplicate callbacks cannot
merge evidence or counters twice.

The existing API-tool sweeper wakes an interrupted owner through its normal run
lock. Recovery activates durable child handoffs and marks unobserved direct calls
as interrupted; it does not blindly repeat them. All MCP interaction outcomes are
preserved and exposed through the existing interaction contract before replanning.

## Verification

Run:

```sh
php artisan test tests/Feature/Agent/AgentWaveCoordinatorTest.php tests/Feature/Agent/AgentWaveParallelProcessTest.php tests/Unit/Agent/AgentWaveExecutorTest.php tests/Feature/ApiTools/ApiToolContinuationTest.php
```

The executor tests use real Artisan subprocesses and verify overlapping PIDs,
error/timeout isolation, the cross-wave barrier and the cumulative deadline.
The mixed integration test runs the actual coordinator and KB/API task handlers
in distinct forked processes with a shared SQLite WAL database. A rendezvous inside
both retrieval and HTTP fixtures makes sequential execution fail; the following
planner invocation and dependent action must occur after both finish. All network
responses are fixtures; these tests do not contact production connectors.
