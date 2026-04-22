---
name: promote-decision
description: 'CONSUMER-SIDE template — use this skill in a consumer repository that posts knowledge to AskMyDocs, NOT when editing AskMyDocs itself. Triggers when an architectural / technical / process decision emerges during a work session and should be promoted to the canonical knowledge base as a typed decision document (ADR-style). Draft only — produces Markdown with frontmatter for human review, never commits. Example triggers: we decided to X, let us go with Y approach, we are standardizing on Z.'
---

# Promote decision (consumer-side template)

> **Banner:** this skill ships in the AskMyDocs repository as a
> **template** for consumer projects. Copy the parent `kb-canonical/`
> folder into your own `.claude/skills/` to activate it in the consumer
> repo. Do NOT invoke it when working on the AskMyDocs code itself.

## Goal

Turn an emerging decision into a canonical `decision` Markdown draft
that a human can review and commit. The resulting file, once ingested
by AskMyDocs, populates `knowledge_documents` with
`canonical_type='decision'` and feeds the knowledge graph.

## Operating instructions

1. **Gather context** — extract from the conversation / transcript:
   - The problem being solved.
   - Options that were considered.
   - The chosen option.
   - Rationale / trade-offs.
   - Consequences (short-term + long-term).
   - Related modules / runbooks / standards.
2. **Look before writing** — check if a similar canonical decision
   already exists in the KB (via `kb.documents.by_type` MCP tool or
   by reading `kb/decisions/` in the repo). If one exists that this
   decision SUPERSEDES, record it under `supersedes:`.
3. **Draft the doc** using the template below. Do NOT invent owners,
   dates, or facts that weren't in the source material. If a detail
   is missing, mark it `TBD` in the draft and list it in the "Open
   questions" section so the human can fill it in.
4. **Output a single Markdown file** with the full frontmatter + body.
   Propose the path: `kb/decisions/<slug>.md`.
5. **Do NOT commit, POST, or otherwise persist** the draft. The human
   reviews, adjusts, and either `git commit` (which triggers the GH
   action → ingest) or runs `POST /api/kb/promotion/promote` with their
   own authenticated token.

## Frontmatter contract (enforced by CanonicalParser server-side)

- `id` — stable business id, e.g. `DEC-2026-0007` (year + zero-padded seq).
- `slug` — kebab-case, unique per project, `[a-z0-9][a-z0-9-]*`.
- `type: decision`
- `project` — consumer project code.
- `status` — usually `accepted` at promotion time; can be `draft` if
  the decision needs more review.
- `owners` — team(s) / role(s), list of strings.
- `retrieval_priority` — 0-100, default 80 for decisions (architectural
  decisions are high-priority context for chat grounding).
- `tags` — topical tags, list of strings.
- `related` — wikilinks to module-kb / runbook / standard / incident
  docs this decision interacts with.
- `supersedes` — wikilinks to decisions this one replaces (if any).
- `summary` — one-line summary for retrieval citations.

## Draft template

```markdown
---
id: DEC-<YYYY>-<NNNN>
slug: <slug>
type: decision
project: <project>
status: accepted
owners:
  - <team>
created_at: <YYYY-MM-DD>
updated_at: <YYYY-MM-DD>
tags:
  - <tag>
related:
  - "[[<related-slug>]]"
supersedes: []
retrieval_priority: 80
summary: <one line>
---

# Decision: <title>

## Summary

<1–3 sentences stating what was decided.>

## Context

<Why the decision was needed. What changed, what constraints appeared.>

## Decision

<The concrete choice, phrased unambiguously. Include numbers where relevant.>

## Why

<Rationale — trade-offs, alternatives weighed, constraints honored.>

## Consequences

<What this decision implies: operational impact, cost, dependencies,
follow-up work.>

## Do

- <actionable item reflecting the decision>

## Do Not

- <explicit anti-pattern to avoid>

## References

- [[<related-doc-slug>]]
- <external URL if relevant>

## Open Questions

- <anything the human still needs to clarify — delete this section if none>
```

## Vincoli

- Un solo tema per file. Se la decisione ne tocca due, splitta e
  wikilinka.
- Non inventare riferimenti. Se non sai un owner, scrivi `TBD`.
- Tono tecnico operativo, non narrativo.
- Lunghezza tipica: 40–150 righe. Se si estende oltre 300 righe
  probabilmente stai mescolando più decisioni.
