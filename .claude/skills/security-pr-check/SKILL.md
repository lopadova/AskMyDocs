---
name: security-pr-check
description: Review an AskMyDocs PR for security-rule compliance, especially AI/MCP/widget changes, and drive the existing R40 local critic plus R36 cloud reviewer loop. Trigger on security PR review, secure PR check, AI PR, MCP/widget action review, or checklist PR.
---

# Security PR Check

This skill augments, and never bypasses, the repository's R36/R40/R46 workflow.

## Review

1. Capture base/head and changed files. Run `npm run security:rules` first when the
   PR changes security instructions, skills or CI wiring.
2. Map every changed source/sink to the relevant SEC IDs. For AI/MCP/widget diffs,
   invoke the `secure-ai-surface` method and explicitly inspect all equivalent
   provider, stream, fallback, queue, registry and error paths.
3. Check authorization order, tenant/ownership scope, fail-closed defaults,
   atomic invariants, safe logs/errors, bounded resources and negative tests.
4. Classify concrete bugs/security/test gaps as must-fix; architecture/documentation
   improvements as should-fix unless required for enforceable coverage.

## Required delivery loop

- Run fresh local fast tests appropriate to the diff.
- Run `scripts/local-critic-loop.sh <base>` and resolve every must/should-fix.
- Run local Playwright in the R46 position when the diff is not Markdown-only.
- Open the PR with `--reviewer copilot-pull-request-reviewer`.
- Keep CI and cloud review in the canonical loop. Add `run-e2e` only after unit CI
  and cloud review are clean. Do not merge unless the user asks and all gates pass.

The PR body lists adopted rule IDs, tests/evidence, infrastructure-only residuals
and any consciously non-applicable controls. Never call a skipped/external check green.
