# Composed API tools: local delivery

The feature is on branch `feature/composed-api-tools`, based on `origin/develop`. No push, tag or PR is part of this delivery. The original checkout remains served by Herd at `https://askmydocsdev.test`; it has not been repointed to this worktree.

## Enable in the intended environment

1. Install the locked dependencies and run the host migrations, including both `2026_10_26_160000` and `161000`.
2. Set `API_TOOLS_ENABLED=true` in that environment, keep `APP_URL=https://askmydocsdev.test` aligned with the existing Herd site, and rebuild the frontend. The committed default is false.
3. Use a shared cache lock store (Redis or file on one host) and a persistent queue. Run at least two workers for queue `api-tools`, as well as the existing agent queue. `API_TOOLS_QUEUE_CONNECTION` may select a queue connection; an empty value uses the host default. The application scheduler must run for recovery and compatibility sweeps. Set the worker timeout above the largest admitted tool deadline (the format caps it at 600 seconds) and set that queue connection’s retry_after above the worker timeout. Node leases cover the whole run deadline plus a margin, while completed-step recovery bypasses orphaned leases safely.
4. Open `/app/{teamHash}/admin/api-tools` as an administrator. The page lives beside connectors. Create a tool, choose a project, map public inputs, configure simulation/live cases, save, validate, run both kinds of test and publish. Disabling/re-enabling retains the explicit operator intent.

In this worktree no production environment file or primary database was copied/migrated, and the Herd link was kept intact. The new UI was inspected via an isolated HTTPS Vite harness at the same Herd hostname on a separate port, with simulated admin API responses. This proves rendering/keyboard/mobile behavior; it is distinct from an authenticated browser test against migrated host APIs. API authentication, scope and publishing are covered by feature tests.

## Repeat the checks

```sh
vendor/bin/phpunit tests/Unit/ApiTools tests/Feature/ApiTools
npm test -- frontend/src/features/admin/api-tools frontend/src/features/admin/api-connectors --configLoader runner
npm run typecheck
npm run build
```

Network checks are explicit opt-ins, use public read-only JSONPlaceholder data and never send tenant credentials:

```sh
API_TOOLS_LIVE_SMOKE=1 vendor/bin/phpunit tests/Feature/ApiTools/ApiToolRuntimeTest.php --filter test_live_public_read_only_chain_and_baseline_have_equivalent_outputs
API_TOOLS_LIVE_PARALLEL=1 vendor/bin/phpunit tests/Feature/ApiTools/ApiToolParallelProcessTest.php --filter test_two_real_https_requests_overlap_in_distinct_node_worker_processes
```

The live chain verified user → posts → comments mappings, all three current verification kinds and initial publication. Baseline and composed outputs matched; both issued three HTTP requests. One observed sample was 683 ms baseline versus 1222 ms composed, including revision verification and queued job delivery drained inside the test process. These are not production agent latency or token savings.

The live parallel check used two NodeJob worker processes and real HTTPS requests, with a request barrier and monotonic send/response timestamps. The measured overlap was approximately 294 ms; both responses were HTTP 200, outputs were 1/2 and the shared budget counted exactly two requests. The output node waited for its declared dependencies. The default test uses a deterministic fake transport rendezvous and shared SQLite/file locks; the opt-in check proves network overlap separately.

Enable `API_TOOLS_MEASUREMENTS_ENABLED=true` for both baseline and composed agent runs to record provider-reported prompt/completion tokens and model latency without prompts or answer bodies. Compare equivalent terminal runs with:

```sh
php artisan api-tools:compare-runs BEFORE_RUN_UUID AFTER_RUN_UUID --tenant=TENANT
```

The comparison includes model calls, actual reported token counts, wall/model latency, logical actions and physical requests. Missing usage stays `null` with `tokens_complete=false`; it is never estimated. No claim of measured LLM token savings is made by the network fixture checks, which execute no model.

## Legacy Edit relation and GESCAT

The relation editor now derives source fields from successful real list responses at their actual `items_path`, unions fields across all observed rows and preserves nested paths/types/case. Its refresh uses the connector's existing authenticated, non-persistent `testConfig` path. Targets are only configured LLM parameters, preserving `to_location`; constants and secrets cannot be overwritten. Missing/incompatible saved mappings remain visible and block saving.

Read-only checks against the existing local GESCAT configuration succeeded: list route `gescat_cerca_ordini` returned HTTP 200 with one bounded order, `ord_ID` was an integer, and the detail call mapped to the configured integer query parameter returned HTTP 200. No route, status, credential or database configuration was changed.

A pre-existing configuration discrepancy remains: route 27 (`gescat_dettaglio_ordine`) is currently classified as `list`, although the relation backend requires `detail`. The editor preserves the selected route, explains the discrepancy and blocks an invalid save. Correct its classification in the route configuration before saving that relation. This delivery does not silently change the primary database.

## Operational limits

The first release is read-only. Bounded iteration is sequential inside its node; graph branches are genuinely concurrent. A queued worker can stop new HTTP attempts on cancellation/deadline, but cannot retract an already sent request. A crash before a GET response is durably committed can repeat that read on retry; committed completed steps are reused. Production uses shared locks; process-local array locks are suitable only for tests. A real authenticated browser journey against the fully migrated primary host and production-load performance remain deployment checks.

## Delivery verification and commits

The extended PHP run passed 229 cases / 1,945 assertions; its two network opt-ins were skipped there and executed separately with real HTTPS, both passing (33 assertions). Two additional deterministic transform tests passed (8 assertions). Notification/API preference and tenant-guard checks passed 115 cases / 445 assertions. These sets overlap and should not be summed as unique tests.

The frontend verification passed 133 tests across its API tools, connector and navigation suites, plus TypeScript and both app/widget builds. The browser harness covered light/dark layouts at 1440 and 390 pixels, keyboard selection/focus and a single expanded call, with simulated backend responses and zero browser errors. Per-call runtime diagnostics and async refresh were additionally covered by component tests after those screenshots.

Functional local commits:

- `063e2485f` — definitions, typed bindings and models.
- `85e011c67` — lifecycle, verification history, compatibility and portability.
- `a8db05002` — queue graph runtime, budgets and durable agent/chat handoffs.
- `88fa58e1f` — administrator editor, APIs, AI drafts and test diagnostics.
- `8e25754c3` — legacy Edit relation using observed fields.
- `099703a73` — nullable global connector scope.
- `f364fdbae` — tenant architecture guards for the new domain models.
- `d47656f71` — opt-in measurements and equivalent-run comparison.
- `797a79a9c` — preserve references when adding replacement calls.
- `2739f233d` — deterministic operations and key join tests.

The source checkout's pre-existing change to `tests/Feature/Agent/AgentChatModelScopeTest.php` was preserved. No branch/tag publication, pull request, main database migration, route reclassification, primary environment change or Herd repointing was performed.
