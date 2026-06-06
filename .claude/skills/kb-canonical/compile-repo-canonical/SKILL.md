---
name: compile-repo-canonical
description: 'CONSUMER-SIDE template — use in a consumer repository that posts knowledge to AskMyDocs, NOT when editing AskMyDocs itself. Triggers when the team wants to bootstrap or refresh the ENTIRE canonical knowledge base of a repository in one pass: survey the codebase, plan the typed-document inventory, and emit a full cross-wikilinked kb/ tree (project-index + modules + decisions + standards + integrations + domain-concepts + runbooks + rejected-approaches). Draft only — produces Markdown files under kb/ for human review, never commits and never POSTs. Example triggers: generate the canonical docs for this repo, compile the knowledge base, bootstrap kb/ from the codebase, document the whole project in canonical mode.'
---

# Compile repository canonical KB (consumer-side template)

> **Banner:** this skill ships in the AskMyDocs repository as a
> **template** for consumer projects. Copy the parent `kb-canonical/`
> folder into your own `.claude/skills/` to activate it. Do NOT invoke
> when editing AskMyDocs itself — AskMyDocs is the RAG engine; the
> canonical content lives in the downstream apps that call it.

## Goal

Produce, in a single guided pass, the **whole canonical knowledge base**
for a repository: a `kb/` folder of typed Markdown documents with valid
frontmatter and Obsidian-style `[[wikilinks]]`, structured so that once
ingested by AskMyDocs it populates `knowledge_documents`, `kb_nodes` and
`kb_edges` into a navigable, graph-aware KB.

This is the **repository-wide orchestrator**. The single-artifact
skills (`promote-decision`, `promote-module-kb`, `promote-runbook`,
`link-kb-note`) are the per-document tools; this skill plans the set,
then applies those same contracts across the codebase at once.

The output is always a **draft tree**. Humans review and commit (git
push → `ingest-to-askmydocs` GH action) or `POST /api/kb/promotion/promote`
with an authenticated token. ADR 0003 — the promotion boundary is never
crossed by the skill itself.

## The 9 canonical types and where each file lives

The `type:` field is validated server-side against this exact set, and
each type has a conventional folder under the KB root (this mirrors
`CanonicalType::pathPrefix()`):

| `type:`             | Folder              | Node label         | Use for |
|---------------------|---------------------|--------------------|---------|
| `project-index`     | `kb/` (root)        | `project`          | One per repo. The map: what the system is, its modules, entry points, glossary links. |
| `module-kb`         | `kb/modules/`       | `module`           | One per significant module / subsystem / bounded context. |
| `decision`          | `kb/decisions/`     | `decision`         | ADR-style: a choice made, with rationale + consequences. |
| `runbook`           | `kb/runbooks/`      | `runbook`          | Operational procedure: trigger → actions → rollback → escalation. |
| `standard`          | `kb/standards/`     | `standard`         | A rule/convention the team enforces (naming, security, API shape). |
| `incident`          | `kb/incidents/`     | `incident`         | Post-mortem of a real incident. Only if real ones exist. |
| `integration`       | `kb/integrations/`  | `integration`      | A connection to an external system (payment, SSO, queue, third-party API). |
| `domain-concept`    | `kb/domain-concepts/`| `domain-concept`  | A glossary entry: a core domain term with a precise definition. |
| `rejected-approach` | `kb/rejected/`      | `rejected-approach`| An option explicitly dismissed — surfaced to stop the LLM re-proposing it. |

Do NOT invent files of a type the repo has no evidence for. A greenfield
repo with no real outage history has **zero** `incident` docs — that is
correct, not a gap.

## Operating instructions (phased)

Work top-down. Confirm the plan with the user before writing the full
tree — bootstrapping a KB is high-leverage and easy to over-generate.

### Phase 0 — Confirm scope and project key
- Ask (or infer + confirm) the **project key** — the tenant/project
  code that goes in `project:` on every doc (e.g. `ecommerce-core`,
  `hr-portal`). Every file in the tree uses the SAME project key.
- Confirm whether this is a **fresh bootstrap** (no `kb/` yet) or a
  **refresh** (a `kb/` already exists — then read it first and propose
  incremental updates that preserve each existing doc's `id`/`slug`,
  per the by-slug check below).

### Phase 1 — Survey the codebase (evidence only)
Read, do not guess. Build your inventory from verifiable sources:
- Top-level layout, package/module manifests, the dependency file
  (`composer.json`, `package.json`, `go.mod`, `pyproject.toml`, …).
- README / existing `docs/` / ADR folders.
- Directory structure that reveals modules / bounded contexts.
- Public interfaces (routes, controllers, CLI commands, event handlers,
  exported APIs) — describe contracts, not implementation.
- Config that names external systems (integrations) and conventions
  (standards).
- Any existing `kb/` content (refresh mode).

If the repo already exposes the AskMyDocs MCP tools, use
`kb.documents.by_type` / `kb.documents.by_slug` to avoid duplicating
existing canonical docs.

### Phase 2 — Produce the inventory plan (STOP for human review)
Before writing any file, output a table the user can edit:

```
| type           | slug                  | title                       | source evidence            | priority |
|----------------|-----------------------|-----------------------------|----------------------------|----------|
| project-index  | <repo>-index          | <Repo> — project map        | README, top-level layout   | 90       |
| module-kb      | module-checkout       | Checkout module             | app/Checkout/*, routes     | 75       |
| decision       | dec-...               | ...                         | docs/adr/0001..., commits  | 80       |
| standard       | std-...               | ...                         | .editorconfig, lint config | 70       |
| integration    | int-stripe            | Stripe payments             | config/services, SDK usage | 75       |
| domain-concept | concept-...           | ...                         | model names, glossary      | 60       |
```

Wait for the user to trim/approve. **Quality over coverage** — a tight,
accurate set beats an exhaustive set padded with speculation.

### Phase 3 — Draft the tree, top-down
Write files in dependency order so wikilinks resolve to slugs you've
already minted:
1. `project-index` first (it references everything else).
2. `module-kb` docs (the structural backbone).
3. `decision`, `standard`, `integration`, `domain-concept`.
4. `runbook` (reference the modules they operate on).
5. `rejected-approach` last (reference the decisions that dismissed them).

Use the per-type templates below. Reuse the single-artifact skills'
section structures verbatim where they exist (`promote-module-kb` for
module bodies, `promote-decision` for decisions, `promote-runbook` for
runbooks) — this skill only adds the cross-document planning + linking.

### Phase 4 — Wire the graph via wikilinks
Edges are derived server-side from frontmatter + body, you never write
`kb_edges` directly. You control them through links:
- `related:` wikilinks → `related_to` edges (the general "see also").
- `supersedes:` wikilinks → `supersedes` edges (a decision replacing an
  older one). The replaced doc should carry `superseded_by:` back.
- In-body `[[slug]]` mentions → edges too. Link a module to its
  decisions, a runbook to the module it operates on, a rejected-approach
  to the decision that killed it, a domain-concept to the modules that
  use it.
Aim for a **connected** graph: every non-index doc should be reachable
from the `project-index` in one or two hops. Dangling wikilinks (to a
slug you didn't create) are allowed — they become `dangling` graph
placeholders — but minimise them.

### Phase 5 — Self-validate against the parser, then output
Before presenting the tree, check every file against the server-side
`CanonicalParser` rules (see checklist). Then output each file with its
proposed path, and finish with an **ingest plan**:
- the file list grouped by folder,
- how many `related`/`supersedes` links you created,
- any `TBD` markers the human must fill,
- the exact next step (commit to `kb/` → GH action, or
  `POST /api/kb/promotion/promote`).

**Never** `git commit`, never `POST`, never call the `promote-*` skills
to persist. Draft only.

## Frontmatter contract (enforced server-side by CanonicalParser)

These fields are **validated** — get them wrong and the doc degrades to
non-canonical ingestion (R4):

- `slug` — **required**, kebab-case, must match `^[a-z0-9][a-z0-9-]*$`,
  unique **per project** (two different projects MAY share a slug).
- `type` — **required**, exactly one of the 9 values above.
- `status` — **required**, one of `draft` / `review` / `accepted` /
  `superseded` / `deprecated` / `archived`. Use `accepted` for docs that
  reflect current reality; `draft`/`review` for ones still being curated.
  `accepted` and `review` are retrievable; the rest are penalised/hidden.
- `retrieval_priority` — integer `0..100` (default 50). Suggested:
  `project-index` 90, `decision` 80, `standard`/`module-kb`/`integration`
  75, `runbook` 70, `domain-concept` 60, `rejected-approach` 50.

These fields are **parsed** (used to build the graph / citations) but not
hard-validated — still fill them honestly:

- `id` — stable business id (e.g. `MOD-ECOM-CHK-001`, `DEC-2026-0007`).
- `project` — the project key (same on every file in the tree).
- `owners` — list of team/role strings. Use `TBD` if unknown, never guess.
- `created_at` / `updated_at` — `YYYY-MM-DD`.
- `tags` — list of topical strings.
- `summary` — one line, used in retrieval citations.
- `related` — list of `"[[slug]]"` wikilinks → `related_to` edges.
- `supersedes` / `superseded_by` — lists of `"[[slug]]"` wikilinks.

Quote wikilinks in YAML (`"[[slug]]"`); unquoted `[[slug]]` is legal but
the quoted form is unambiguous.

## Per-type body skeletons

### project-index — `kb/<repo>-index.md` (or `kb/index.md`)
```markdown
---
id: PRJ-<CODE>-INDEX
slug: <repo>-index
type: project-index
project: <project>
status: accepted
owners: [<team>]
created_at: <YYYY-MM-DD>
updated_at: <YYYY-MM-DD>
tags: [index]
retrieval_priority: 90
summary: <one-line description of the whole system>
related:
  - "[[module-...]]"
  - "[[dec-...]]"
---

# <Repo> — Project Map

## What this is
<2–4 sentences: what the system does and for whom.>

## Architecture at a glance
<The major moving parts and how they connect.>

## Modules
- [[module-...]] — <one line>

## Key Decisions
- [[dec-...]] — <one line>

## Standards
- [[std-...]] — <one line>

## Integrations
- [[int-...]] — <one line>

## Glossary
- [[concept-...]] — <one line>

## Entry Points
- <HTTP / CLI / queue / event entry points>
```

### module-kb, decision, runbook
Use the bodies from `promote-module-kb` (9 sections), `promote-decision`
(Summary/Context/Decision/Why/Consequences/Do/Do Not/References/Open
Questions), and `promote-runbook` respectively — verbatim. Do not
reinvent those structures here.

### standard — `kb/standards/<slug>.md`
```markdown
# Standard: <name>

## Rule
<The convention stated as an enforceable rule.>

## Rationale
<Why it exists.>

## Do / Do Not
- Do: ...
- Do Not: ...

## Scope
<Where it applies / exceptions.>

## Related
- [[module-...]]
```

### integration — `kb/integrations/<slug>.md`
```markdown
# Integration: <external system>

## Purpose
<What this integration provides.>

## Direction & Protocol
<Inbound/outbound, transport, auth model — NO secrets.>

## Data Exchanged
<Shapes / fields, not payload dumps.>

## Failure Modes & Retries
<Timeouts, idempotency, backoff.>

## Owner & Runbook
- [[runbook-...]]
```

### domain-concept — `kb/domain-concepts/<slug>.md`
```markdown
# Concept: <term>

## Definition
<A precise, one-paragraph definition.>

## In This System
<How the concept is realised here — models, modules.>

## Related
- [[module-...]]
- [[concept-...]]
```

### rejected-approach — `kb/rejected/<slug>.md`
```markdown
# Rejected: <approach>

## What was considered
<The option, stated fairly.>

## Why it was rejected
<The concrete reasons.>

## What we do instead
- [[dec-...]]
```

## Self-validation checklist (run before output)

- [ ] Every file has `slug`, `type`, `status`, `retrieval_priority`.
- [ ] Every `slug` matches `^[a-z0-9][a-z0-9-]*$` and is unique in the tree.
- [ ] Every `type` is one of the 9 canonical values; the file is in the
      matching folder.
- [ ] Every `status` is one of the 6 valid values.
- [ ] Every `retrieval_priority` is an integer in `0..100`.
- [ ] Every file carries the same `project:` value.
- [ ] Wikilinks are quoted in YAML; in-body links use `[[slug]]`.
- [ ] The graph is connected: every doc is reachable from `project-index`.
- [ ] No invented owners/dates/facts — unknowns are `TBD` + listed under
      Open Questions.
- [ ] No `incident` docs unless a real incident exists; no type padded
      to look complete.
- [ ] Nothing was committed or POSTed — output is a draft tree only.

## Vincoli

- **Evidence-only.** Describe interfaces and observed behaviour, never
  implementation you didn't read. No speculative architecture.
- **One topic per file.** If a module/decision splits into two, make two
  files and wikilink them.
- **Refresh mode preserves identity.** When a doc already exists, keep
  its `id`/`slug`, bump `updated_at`, touch only changed sections.
- **Draft only.** The human reviews and commits, or POSTs with their own
  token. The skill never crosses the promotion boundary (ADR 0003).
- **Tone:** operational technical documentation, not marketing prose.
  Typical doc 40–150 lines; >300 lines usually means mixed topics.
