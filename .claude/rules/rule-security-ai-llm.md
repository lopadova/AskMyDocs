# Rule: AI/LLM security surface

## Identifier

`SEC-LLM-001`

## Scope

Every path that sends data to or consumes data from a model: `app/Ai/`,
`app/Services/Widget/`, `app/Mcp/`, RAG/ingestion services, guardrails, FinOps,
PII integration, and the widget/SPA renderers under `frontend/src/`.

## Fundamental invariant

An LLM is an untrusted component inside the process. Prompts are guidance, not
authorization. Model output is attacker-controlled input. A setting is not a
security boundary. Every material restriction must be enforced in code before
the read, provider egress, tool call, DOM effect, write, or spend.

## The mandatory gates

1. **Complete provider inventory.** Trace every SDK client, `Http::*` call,
   streaming path, fallback, retry, background job, widget path and test/playground
   path. A control covering only `AiManager` is incomplete if another client can
   reach a provider. New egress paths must fail CI until classified.
2. **Provider/model/base-URL policy.** Provider, model and endpoint are checked
   against code-owned exact allow-lists at the final client-construction/request
   choke point. Tenant/admin settings may select only from that policy. No
   wildcard models, arbitrary base URLs or fail-open fallback. Provider choice is
   also a data-subprocessor decision.
3. **PII and secret egress.** Secrets never enter prompts. All text-bearing
   provider paths apply the repository PII policy before egress; unknown contexts
   take the protective default. Any allow/bypass requires a reason, owner and
   test. Images and oversized/binary payloads require a separate policy because
   regex redaction cannot inspect pixels and can corrupt base64.
4. **Prompt-injection containment.** Retrieved chunks, document text, widget DOM
   snapshots, conversation history and tool results remain untrusted `user` data,
   visibly delimited with delimiter-collision handling. They must never be
   promoted to `system`/developer instructions. Delimiters reduce risk; code
   authorization and allow-lists contain impact.
   **Tool results are data, never instructions.**
5. **Tool authorization before validation/data access.** Each AI/MCP/widget tool
   goes through one non-bypassable server-side authorizer using the immutable
   initiating identity and current permissions. Tenant/ownership scope is applied
   in the query. The AI channel cannot expose more than the equivalent UI/API.
   Missing identity, policy, scope or registry entry means deny.
6. **Output is data, never code.** Validate structured output against a closed
   schema and reject unknown fields. Model-controlled HTML, SQL, paths, shell,
   URLs, component names and class/style values are never executed dynamically.
   Text uses safe DOM APIs; renderer types and props use explicit allow-lists.
   Capability-bearing props are removed, not merely escaped. `innerHTML` is
   allowed only for compile-time constants or isolated test fixtures.
7. **Bounded work and atomic spend.** Enforce per-request iterations, context,
   tokens, tool-output rows/bytes and timeouts plus rate and daily cost limits.
   Budget reservation is atomic and happens before provider work; record actual
   usage after. Streaming is not exempt. A zero/missing/invalid limit cannot
   silently disable protection.
8. **Safe diagnostics.** Client/model responses receive generic correlated
   errors. Logs and persistence never contain raw prompts, provider bodies,
   secrets, DB exception messages, SQL bindings, tool arguments or PII. Record
   exception class, safe code, reference, hashes and bounded lengths. Debug traces
   are runtime-blocked in production, not merely hidden by UI/config convention.
9. **External response validation.** Validate status, content type, bounded body,
   expected JSON shape and provider request/tool identifiers. Do not retry
   permanent 4xx errors; cap retries/backoff and never duplicate billable or
   mutating effects.
10. **Tests prove negative paths.** Cover unknown provider/model/context/tool,
    anonymous and cross-tenant access, injected prompt/tool result, malicious
    markup/props, PII in all paths, concurrent budget consumption, malformed
    provider responses, debug-in-production and fallback/retry behavior.

## AskMyDocs choke points to inspect together

- `app/Ai/AiManager.php` and every provider construction/direct HTTP call;
- `app/Services/Widget/WidgetOrchestratorService.php`,
  `WidgetAiToolRegistry.php`, `WidgetToolValidator.php`, `WidgetSnapshotValidator.php`
  and `WidgetPiiMasker.php`;
- `app/Mcp/Client/McpToolCallingService.php`, `McpToolAuthorizer.php`, its adapter,
  and every registered MCP tool;
- `frontend/src/widget/core/`, `frontend/src/widget/dom/` and
  `frontend/src/widget/ui/UiArtifactRenderer.ts`;
- FinOps, guardrails, PII observers/middleware and every queue/streaming path.

## AI hardening lessons that must not regress

- Idempotency uniqueness includes the effective identity/session and operation
  fingerprint; reservation/placeholder creation is atomic under concurrency.
- The initiating user is captured once server-side, transported immutably and
  re-authorized when an async effect executes.
- Tool and DOM action registries are checked in both directions against executable
  implementations; configuration missing or stale fails closed.
- Tool arguments/results and snapshots are data, not system instructions or
  markup. Fake image payloads do not bypass real decoding, MIME and size checks.
- Provider policy, PII gate, budget and audit cover every construction/execution
  path, including fallback, standalone, stream and error branches.

## Review blockers

Any bypassable entrypoint, fail-open default, raw diagnostic leak, model-driven
authorization, dynamic execution, unbounded provider/tool loop, or untested
security branch is a must-fix.
