---
name: security-audit
description: Perform a systematic AskMyDocs application-security audit using the SEC-* rules, with source-to-sink tracing, effective-population coverage, evidence, severity and focused remediation PRs. Trigger on security audit/checklist/compliance review, data breach readiness, or verify all security fixes.
---

# Security Audit

## Method

1. Establish scope, commit SHA, environment assumptions and what cannot be proven
   from the repository. Never infer Cloudflare, DNS, IAM, Redis or DB grants.
2. Inventory entrypoints and sinks across HTTP, CLI, queue, scheduler, MCP, widget,
   Tauri/webview, uploads, exports, webhooks and every AI provider path.
3. Apply the rule groups under `.claude/rules/rule-security-*.md` plus R21 and
R30-R32. Use `docs/security/LORENZO_SECURITY_EXPERIENCE.md` to prove every
   source rule has a disposition.
4. Trace attacker-controlled sources to effects. Confirm authorization before data
   access, tenant scope in queries, safe validation/encoding at the final sink,
   complete fallback/error coverage and atomicity for race-sensitive invariants.
5. Distinguish four evidence states: implemented, regression-tested, deployment
   configuration required, infrastructure verification required.

## Prescreen

```bash
rg -n "unserialize|decrypt\(|whereRaw|orderByRaw|DB::raw|shell_exec|exec\(|innerHTML|dangerouslySetInnerHTML" app frontend/src
rg -n "Http::|curl_|redirect|webhook|upload|Storage::|file_put_contents|DOMDocument|simplexml" app routes config
rg -n "createToken|tokens\(\)->delete|tenant_id|authorize\(|Gate::|can\(" app routes
rg -n "APP_DEBUG|debug|fake|CORS|allowed_origins|trusted_prox|throttle|rate" app config routes .github
npm run security:rules
```

Matches are candidates, not automatic findings. Missing controls are absences and
require sibling/entrypoint comparison and architecture tests.

## Finding format

For each finding provide: ID/rule, severity, affected path and population, concrete
impact, source-to-sink evidence, proof/test, focused remediation and residual
infrastructure step. Conditional severity must be labeled conditional.

Also record a `SANO` section for reviewed false positives and sound boundaries so
future audits do not repeat work. Update existing security status/checklist documents
when implementation changes. Never paste actual PII, tokens or secrets.

## Remediation discipline

Critical/high issues receive focused PRs with negative regression tests. A control
that covers only one of multiple paths remains open. Do not declare closure because
a prompt, setting, edge rule or documentation says the behavior is safe.
