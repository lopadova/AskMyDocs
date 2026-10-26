# Autonomous composed API tools

Status: implemented behind `API_TOOLS_ENABLED=false`.

A tool is a host object, independent of any connector. Its immutable revision declares public inputs, steps, bindings, explicit collection limits, output and tests. Each API step references an existing tenant/project endpoint. Credentials stay with the connector. No new MCP server is introduced.

`ToolGraphCompiler` emits registered `askmydocs.api_tool.operation` nodes and dependency wires for bindings plus explicit waits. `QueueGraphCoordinator` and vendor `NodeJob` schedule independent roots on separate workers. The synchronous `GraphRunner` is not used for production execution. Iterations are explicitly bounded and execute inside their owning node; independent graph branches can run concurrently.

A run freezes the revision and endpoint fingerprints. Every worker restores tenant, actor, project, locale and timezone, then rechecks actor and endpoint availability. A changed dependency stops the frozen run rather than silently adopting a new contract. Authentication, SSRF, request planning, dispatch/retries and transformations remain in the connector executor. GET and HEAD are the only permitted methods.

The generic flow graph, input and output carry opaque references only. Host run inputs, outputs, continuations, step values, diagnostics and verification results are encrypted. Composed endpoint cache entries are encrypted in a separate namespace, with actor, locale, project, semantic dependency fingerprint and a private credential cache scope. Connector call logs for composed execution retain the run reference/status, never raw intermediate values. Administrators receive masked diagnostics.

HTTP middleware debits every actual attempt, including retries and authentication requests, atomically against the shared run budget. Cache hits cost zero. The remaining parent agent/chat allowance caps each composed run. Elementary collection accounting also measures actual attempts; page counts are independent of request counts. Collection defaults to a single request and requires explicit page/item caps for bounded traversal. Cursor URLs are not followed by composed tools; configure a cursor value field.

A missing value, `null`, false, zero and an empty list remain distinct. Lists never become scalars implicitly. Bindings explicitly choose a complete value, an index or a projection. Iteration with permitted partial errors always returns `{items, errors, _partial}`, including an empty or successful result. Public output selection never deletes intermediates.

Agent handoffs use `waiting_api_tool`, encrypted continuations and a durable terminal-run outbox. Direct chat saves its complete provider tool-call history and remaining siblings, then resumes automatically from a queue job. Its iteration and request allowance persist across every asynchronous continuation. Duplicate completion, cancellation and an early completion before parent unlock are covered. The minute sweep repairs delivery gaps, restores completed domain steps to opaque flow results after worker interruption, enforces deadlines and re-dispatches unconsumed handoffs.

Publication intent, compatibility and operational health are separate. The initial publication requires current static, simulation and live checks. Live checks must have issued at least one HTTP request; fixture-only runs cannot qualify. Config changes deprecate published tools and enqueue verification. Historical matching content enables a short real smoke case, while current authorization, generation, revision, tests hash and content hash still guard promotion. Verification queue identities capture their generation/hash/revision at dispatch; an older lock cannot suppress or release the latest configuration's check.

The default threshold is three consecutive production failures. Success and contract-permitted partial success reset it. User input failures, cancellations, exhausted limits and revoked scope do not count. Manual disable and revoked permissions always win. Status transitions create deduplicated in-app events only.

Portable tools use `askmydocs.api-tool`, version 1, with connector names, endpoint slugs and method references. Import resolves existing local dependencies uniquely and creates an unpublished draft. It never imports credentials or verification history. The existing `askmydocs.api-connector-config` v1 JSON/ZIP contract remains unchanged.
