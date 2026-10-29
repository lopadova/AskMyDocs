# Agent research waves

Research turns use one planner across documents, API routes and MCP connections.
With the classic planner, interpretation and the first-wave proposal share one AI
response. The host validates the interpretation before validating the proposal
against the current authorized tools, identifier constraints and live-source
coverage. A valid proposal starts the first wave without another planning call;
a missing or rejected proposal, or a host-corrected focus, uses the ordinary
planner with the validated interpretation. The planner schedules all relevant independent actions whose
inputs are available. No recursive document investigation
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

Document workers combine literal lookup for the validated subject identifiers
with semantic retrieval, without an assessor or local replanning loop. Exact
candidates take precedence over the semantic top results. Each candidate is
opened through `KbSourceReader` before it becomes evidence, preserving verified
email originals, ACLs and distinct windows of long documents. Conceptual search
seeds inherit their understood subquestion's subject; a query with one explicit
subject does not import unrelated identifiers from other subquestions. Partial
lookup failures preserve successful sources and return a warning to the parent.
Semantic sources remain candidates for the common planner even when they do not
repeat an identifier, so general policies can still inform a specific request.
Unchanged evidence already collected by earlier waves does not consume the next
search's source limit; the parent retains it while new passages can be added.
The configured retrieval-profile requirement remains enforced
for document search; a missing profile does not prevent sibling live-source calls.
Evidence is reauthorized before each common decision and final synthesis retains
the existing grounding checks. Context-only, provenance and clarification turns
retain their existing handling.

## Limits and diagnostics

`AGENT_WAVES_ENABLED` defaults to `true`; disabling it restores the previous
collection path. `AGENT_WAVE_CONCURRENCY` defaults to 10. If a wave contains more
ready actions, execution uses bounded batches and still performs only one common
evaluation after all batches. `AGENT_MAX_ACTIONS_PER_PLAN` defaults to 20.

`AGENT_COMBINED_INITIAL_PLANNING` defaults to `true` for classic planning.
Capability and shadow modes retain their router and independent plan checks.
The combined proposal is consumed once, before any completed action or wave;
it is kept outside the semantic conversation cache and is discarded when the
authorized capability snapshot changes. Source grounding, semantic checks and
post-wave common planning remain in place. Combined calls appear as
`question.initial_plan` in diagnostics; `planner.initial_plan` measures local
validation rather than another provider call.

`AGENT_WAVE_TIMEOUT_SECONDS` defaults to 45 and limits the entire wave, including
all batches. The parent further caps this against the worker deadline and answer
reserve and the remaining collection budget. `AGENT_WAVE_MINIMUM_DISPATCH_SECONDS`
defaults to 5 (capped at the configured wave timeout). A common planning call must
leave that useful dispatch window after its estimated duration. The host checks
again after planning, before reserving calls or creating executions, because a
provider may exceed its estimate. A refused round keeps all collected evidence,
returns `partial` / `time_limit` for synthesis, and records `research.skipped`
with the remaining and required seconds. Successful siblings survive another
task's failure or timeout. A task
whose physical outcome cannot be observed is charged its allocated quota
conservatively. Logical and physical limits remain shared; workers use detached
counters and disjoint physical allowances. Budget confirmation is exposed after
allowed siblings have been collected.

Events include `wave.started`, `wave.completed` and per-tool outcomes. Durable
`result_json.waves` records wave membership and deferred actions. Debug exports
include whether waves are enabled, concurrency and timeout configuration.

Subprocess results travel through private temporary files using an atomic,
versioned envelope with Base64-encoded serialized data. Console output is disabled
and never parsed as a result: bootstrap/log output and binary return values must
not turn a completed tool into a failed observation. Files are size-bounded and
removed after child processes have stopped.
The executor passes a PHP/Artisan argument array directly to the process API,
so the managed PID belongs to the PHP task rather than an intermediate shell.
The timeout therefore stops the task before the wave returns and removes its
transport files.

### Debug 113 follow-up (29 October 2026)

Run 247 confirms the Cloud queue improvement: delivery took 260 ms. The combined
initial call took 17.345 s; the first concurrent KB/API wave took 3.402 s and
collected five documents (including the four exact matches from the recall
regression). The next common plan took 29.890 s. Its three actions received only
1.863 s before the answer reserve, all timed out, and child tool spans continued
after the parent's wave end. The API failure's 99 physical requests represent
the conservatively charged allowance, not 99 observed network calls.

The export was captured before completion. A later read of the live run found
`partial` / `time_limit`, with `worker.execute` completed in 118.373 s and
`provider.synthesis` completed in 60.042 s. There was no queue stall in this run.
A bounded, no-AI process probe on that Linux environment confirmed different
managed/task PIDs for a shell command and identical PIDs for direct argv.
The new admission guard avoids spending another planning call when its next wave
cannot fit, and rechecks before dispatch if planning takes longer than estimated.
Model selection and evidence grounding are unchanged. These local changes need
deployment before their effect on live response times can be measured.

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
php artisan test tests/Feature/Agent/CombinedInitialPlanningTest.php tests/Unit/Chat/CombinedQuestionPreprocessorTest.php
php artisan test tests/Feature/Agent/AgentWaveKnowledgeSearchTest.php tests/Feature/Agent/ExactKnowledgeWaveIntegrationTest.php
```

The executor tests use real Artisan subprocesses and verify overlapping PIDs,
error/timeout isolation, the cross-wave barrier, the cumulative deadline, the
managed/task PID identity and the absence of late writes from timed-out tasks.
Coordinator regressions reproduce a slow planner leaving less than two seconds
for research and a next plan whose estimate would consume the useful dispatch
window; both preserve prior evidence without dispatching or charging new calls.
The mixed integration test runs the actual coordinator and KB/API task handlers
in distinct forked processes with a shared SQLite WAL database. A rendezvous inside
both retrieval and HTTP fixtures makes sequential execution fail; the following
planner invocation and dependent action must occur after both finish. All network
responses are fixtures; these tests do not contact production connectors.
The exact-lookup integration regression simulates eight unrelated semantic hits
and four matching documents outside that list. It verifies that both document
searches reach the next common plan with all four authorized sources, deduplicated
and verified against the requested identifier, without additional AI calls in
the workers.
