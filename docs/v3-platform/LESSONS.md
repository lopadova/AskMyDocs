# AskMyDocs v3.0 — LESSONS log

**Purpose:** Append-only log of bugs, rules, discoveries, gotchas, and runtime decisions surfaced during v3.0 implementation. Every sub-agent appends here before committing. Orchestrator injects this file into every subsequent dispatch.

**Format (mandatory for every entry):**

```markdown
## [YYYY-MM-DD HH:MM] Sub-task <ID> — <Title>

**Type:** bug | rule | discovery | gotcha | decision-runtime
**Severity:** low | medium | high | critical
**Applies to:** <area> (e.g. "ConverterInterface implementations", "FE composer", "all PDF processing")

**Finding:**
<2-4 lines: what was discovered>

**Why it matters:**
<1-2 lines: impact on future agents>

**How to apply:**
<1-2 lines: what to do differently>

**References:** <file:line(s) or PR/commit>
---
```

**Rules:**
- Append-only. Never modify existing entries.
- Every sub-task MUST add at least one entry (even `Type: discovery, Severity: low, Finding: nessuna scoperta rilevante`).
- Sort: chronological (newest at the bottom).
- T4.1 will produce `LESSONS-v3.0-digest.md` from this file at the end of v3.0.

---

<!-- ENTRIES BELOW — first entry will be appended by T1.1 sub-agent -->
