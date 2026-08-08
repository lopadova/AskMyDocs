---
applyTo: "**/*.{php,ts,tsx,js,jsx}"
description: "AskMyDocs AI/LLM security gates (SEC-LLM-001)"
---

# SEC-LLM-001 — treat the model as untrusted

Review every AI egress and output path, not only `AiManager`:

- Provider/model/base URL must pass a code-owned exact allow-list at the final
  request/client choke point. Tenant/admin settings cannot expand it; unknown state denies.
- Apply PII policy before every provider egress (SDK, HTTP, stream, fallback, job,
  widget). Secrets never enter prompts. Exceptions need reason, owner and test.
- Retrieved documents, DOM snapshots, history and tool results stay delimited
  untrusted `user` data; never promote them to system/developer messages.
- Authorize each tool before validation/data access using immutable initiating user,
  current permission and tenant scope. Missing/mismatched context denies.
- Validate output with closed schemas. Never execute model HTML, SQL, shell, paths,
  URLs, component names, selectors or props. Use safe DOM APIs; strip capability props.
- Atomically reserve rate/cost budget before provider work; cap iterations, tokens,
  context, tool output, time and retries. Streaming is covered.
- Return generic correlated errors. Never log/persist raw prompts, provider bodies,
  DB exception text, secrets, tool arguments or PII. Production debug is runtime-blocked.
- Validate external status/content-type/body/schema and cap retry/backoff.

# AI hardening regressions to block

Idempotency is unique and atomic under concurrency; identity is captured once and
re-authorized at effect time; action/tool registries match implementations in both
directions; unknown config fails closed; snapshots/tool results are data, not markup
or system instructions; images require real decode+MIME+size validation; provider
policy, PII, budget and audit cover every entrypoint and error/fallback branch.

Canonical details: `.claude/rules/rule-security-ai-llm.md` and
`.claude/rules/rule-security-ai-actions.md`.
