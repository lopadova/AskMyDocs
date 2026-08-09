---
name: secure-ai-surface
description: Audit or harden any AskMyDocs AI, RAG, MCP, widget, provider, prompt, model-output or agent-action surface using SEC-LLM-001 and SEC-AI-ACT-001. Trigger on AI security, prompt injection, new provider/model/tool, MCP tool, widget/host action, model rendering, PII egress, budget or idempotency changes.
---

# Secure AI Surface

Use this skill whenever code sends data to a model, consumes model output, exposes
a tool/action, or changes provider/PII/guardrail/FinOps behavior.

## 1. Build the effective inventory

Search by behavior, not only known classes:

```bash
rg -n "Http::|curl_|new Client|OpenAI|OpenRouter|Anthropic|Gemini|provider|stream|chat\(|generate" app config
rg -n "McpTool|ToolCalling|tool_calls|WidgetAiTool|host.?tool|execute\(|dispatch\(" app frontend/src
rg -n "innerHTML|dangerouslySetInnerHTML|eval\(|new Function|role.*system" app frontend/src
```

Classify every path: provider egress, prompt assembly, tool execution, model-output
rendering, persistence/logging, budget, PII, retry/fallback and async execution.

## 2. Trace every boundary end to end

For each path record:

- trusted initiating identity and tenant source;
- authorization/ownership point and whether it precedes validation/data access;
- provider/model/base URL policy and PII gate at final egress;
- system instructions versus untrusted user/document/snapshot/tool data;
- token/context/tool-output/time/rate/cost limits;
- output schema, renderer/action allow-list and unsafe sink;
- audit and safe diagnostic behavior on allow/deny/failure/replay;
- fallback, retry, stream, queue, playground and legacy equivalents.

Read the implementation at both ends. A registry alone does not prove the executor
cannot do more; a central client alone does not prove all egress uses it.

## 3. Apply the two rules

Use `.claude/rules/rule-security-ai-llm.md` for the complete LLM gates and
`.claude/rules/rule-security-ai-actions.md` for read/mutating/browser actions.
Also apply R21, R30-R32 and the generic security rules for input, runtime and
control coverage.

## 4. Require executable evidence

Add negative tests for each missing boundary. High-value cases include unknown
provider/model/tool/action/context, prompt injection stored in a KB chunk or DOM
snapshot, malicious renderer props, cross-tenant IDs, raw provider/DB exceptions,
debug in production, all provider paths carrying PII, concurrent budget/idempotency
requests and fallback/stream paths.

Tests for concurrency must run concurrent workers and assert exactly one reservation
or effect. Registry contract tests compare policy and executable cases both ways.

## 5. Report

Return a compact table with path, data/effect, existing gates, gap, severity,
test evidence and proposed PR boundary. Separate repository fixes from infrastructure
verification. Never include real secrets or PII. Fix independent findings in focused
PRs; do not hide unresolved scope inside a mega-PR.
