# Canonical knowledge compilation — consumer skill templates

> **IMPORTANT: these skills are TEMPLATES for consumer projects that use AskMyDocs.**
>
> They are NOT meant to auto-activate while you're editing the AskMyDocs
> codebase itself. AskMyDocs is the RAG engine; the knowledge-base content
> lives in **downstream applications** (e.g. `ecommerce-core`,
> `hr-portal`, `finance-close-process`) that call AskMyDocs' APIs.

## How to use these in your consumer repo

1. Copy the whole `kb-canonical/` folder into your consumer project's
   `.claude/skills/` directory.
2. Optionally edit each `SKILL.md` description to match the specific
   AI tools / workflows your team uses.
3. Commit. Any Claude-Code-style agent operating on that consumer repo
   will now trigger the skills contextually — e.g. `promote-decision`
   when the team just finalized an architectural decision in a chat
   session, `session-close` when wrapping up an editorial sprint.

## What the skills produce

Every skill produces a **draft** — a Markdown document with the
canonical frontmatter and the structured sections the type expects.
**No skill ever commits to canonical storage directly.** The human
reviews the draft, commits it to the consumer repo's `kb/` folder, and
the GitHub composite action `ingest-to-askmydocs` (see the AskMyDocs
action.yml) routes it through `POST /api/kb/ingest` — which in turn
parses the frontmatter, populates `knowledge_documents`, dispatches
`CanonicalIndexerJob`, and builds the `kb_nodes` / `kb_edges` graph.

Alternative path: the skill can call `POST /api/kb/promotion/promote`
directly. The endpoint requires Sanctum auth and triggers the same
ingest flow server-side. Useful for teams that don't want to gate on
a git commit.

## The 5 templates

| Skill | Produces | Trigger |
|---|---|---|
| `promote-decision` | ADR-style canonical decision MD | "we decided to X", "let's go with Y approach" |
| `promote-module-kb` | `module-kb` canonical MD with 9 standard sections | "document how module X works", "write the module KB" |
| `promote-runbook` | `runbook` canonical MD (trigger / actions / rollback / escalation) | "turn this procedure into a runbook", "document the incident response" |
| `link-kb-note` | Wikilink additions to existing canonical notes | "connect these documents", "what else relates to X?" |
| `session-close` | Structured list of candidate artifacts | session wrap-up, sprint closeout |

Every produced doc includes:
- YAML frontmatter with `slug`, `type`, `status`, `owners`, `tags`,
  `retrieval_priority`, `related`, and (where applicable) `supersedes`.
- Body with the structural sections the type expects.
- Obsidian-style `[[wikilinks]]` where related canonical docs exist.

## Governance rule

Skills produce drafts. Humans commit to Git (or call
`POST /api/kb/promotion/promote` explicitly with authenticated token).
This trust boundary is enforced by **ADR 0003** — see
`docs/adr/0003-promotion-pipeline.md` in the AskMyDocs repo.
