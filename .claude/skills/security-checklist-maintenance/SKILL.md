---
name: security-checklist-maintenance
description: Maintain the AskMyDocs security audit crosswalk — SECURITY_CHECKLIST.md (88 internal controls + OWASP ASVS 5.0.0 L2 + AISVS 1.0 + Top 10 2025), THREAT_MODEL.md, AUDIT-FINDINGS-2026-08.md — and keep it in sync with LORENZO_SECURITY_EXPERIENCE.md and the security-rules validator. Trigger when a remediation PR merges, when a new route/tool/provider/surface is added, when an evidence-state changes, or when the user asks to update the security checklist / mark a control closed.
---

# Security checklist maintenance

The security audit produces a **living crosswalk**. This skill is how you keep it
truthful. The cardinal rule from the `security-audit` skill holds everywhere: **a
rule existing, or a control marked "Adopted", is not evidence** — a row moves to
a stronger evidence-state only when code + test back it.

## The documents and their relationship

```
LORENZO_SECURITY_EXPERIENCE.md   88 control dispositions (source of the §1 list)
        │   validated by →  npm run security:rules
        ▼
SECURITY_CHECKLIST.md            §1 controls · §2 ASVS · §3 AISVS · §4 Top10 · §5 populations · §6 infra · §7 PR index
        ▲                                   ▲
THREAT_MODEL.md (populations)     AUDIT-FINDINGS-2026-08.md (F-xx findings + SANO)
```

Every control slug in checklist §1 MUST have a disposition row in
`LORENZO_SECURITY_EXPERIENCE.md`, and vice versa. PR B makes the validator
enforce this **bidirectionally** and adds `SECURITY_CHECKLIST.md` to
`requiredFiles`.

## Evidence-states (the only allowed values)

`regression-tested` · `implemented` · `deployment-config-required` ·
`infra-verification-required` · `open` · `N/A`. A row may only advance to
`regression-tested` when a **negative** test exists that fails if the control is
removed. Never mark `regression-tested` off a happy-path test.

## When a remediation PR merges

1. In checklist **§1**, move each control the PR closed to its new state (usually
   `open → regression-tested`), update the *Test evidence* column with the real
   test path, and set the *PR* column to the merged PR number.
2. In checklist **§7**, flip the PR row status to `merged <PR#> <date>` and list
   the exact controls closed.
3. In **§2/§3**, any ASVS/AISVS row whose note pointed at that PR moves state to
   match (a PR closing `content-security-policy` advances the V3/V12 rows).
4. In **§4**, re-evaluate the affected Top-10 aggregate state.
5. In **AUDIT-FINDINGS**, move the finding from *Open findings* to a *Resolved*
   subsection with the closing PR + test path. If review proved it a non-issue,
   move it to **SANO** with the reason.
6. Run `npm run security:rules` — must stay green.

## When a new surface/route/tool/provider is added

1. Add it to the relevant **THREAT_MODEL §1** population row (update the count).
2. Add it to checklist **§5** (per-surface coverage) with its control state.
3. If it is a protected route/tool/gate/role: add the R32 matrix row (that is
   R32, non-negotiable) and confirm the route-exposure gate (PR 7) still passes.
4. If it introduces a NEW control category not in the 88: add a disposition row
   to `LORENZO_SECURITY_EXPERIENCE.md` AND a §1 row AND update the validator's
   expected count. Do not smuggle a new control in as a note.

## Regenerating the standards fragments (§2/§3)

The ASVS/AISVS tables were generated from the OWASP source repos (ASVS 5.0.0,
AISVS 1.0) with a small parser mapping each section to the AskMyDocs control +
state. When OWASP publishes a new point release, re-clone the source, re-run the
parser, and re-map only the changed sections — never hand-edit a requirement's
wording (R9: quote the standard verbatim, abridged only for width).

## Anti-patterns

- ❌ Marking a control closed because the rule file mentions it.
- ❌ `regression-tested` without a negative test.
- ❌ Closing an `infra-verification-required` row from the repo (it needs runtime
  evidence — Cloudflare/DNS/IAM/Redis/DB-grants/emitted-headers/retention).
- ❌ Editing §1 without editing the `LORENZO_SECURITY_EXPERIENCE.md` disposition
  (the validator will fail after PR B).
- ❌ Adding a new protected route without its R32 matrix row + §5 population update.
