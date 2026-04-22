---
name: promote-module-kb
description: 'CONSUMER-SIDE template — use in a consumer repository that posts knowledge to AskMyDocs, NOT when editing AskMyDocs itself. Triggers when the team needs to create or update the canonical knowledge base of an application / infrastructural module. Draft only — produces a module-kb Markdown with the 9 standard sections for human review, never commits. Example triggers: document how the checkout module works, write the module KB for the payments layer, rewrite this module reference doc.'
---

# Promote module KB (consumer-side template)

> **Banner:** this skill ships in the AskMyDocs repository as a
> **template** for consumer projects. Copy the parent `kb-canonical/`
> folder into your own `.claude/skills/` to activate. Do NOT invoke
> when editing AskMyDocs itself.

## Goal

Produce a draft `module-kb` canonical Markdown that stably documents a
module of the consumer system (application module, infrastructural
module, integration layer, API subsystem). The structure is designed
so operators can navigate by section without reading the whole doc.

## Operating instructions

1. **Identify the module target** — confirm with the user which module
   this is about. The module must be concrete enough to have a clear
   slug (e.g. `module-checkout`, `module-cache-layer`, `module-sso`).
2. **Gather source material only from verifiable places**:
   - Code (the module's directory tree, public interfaces).
   - Existing decisions / runbooks / standards (read via
     `kb.documents.by_type` MCP tool or `kb/` folder).
   - Prior incidents or monitoring data shared in the session.
3. **Check for an existing module-kb doc** — use
   `kb.documents.by_slug` MCP tool with the proposed slug. If one
   exists, propose an **incremental update** preserving the frontmatter
   `id` and only touching the sections that changed.
4. **Fill the 9 standard sections** below. Any section that lacks
   verifiable info goes under "Open Questions" at the end; do NOT
   hallucinate architecture you didn't observe.
5. **Wikilink everything you can**: related decisions, runbooks,
   integrations, domain concepts. Links materialize into `kb_edges`.
6. Output the draft. Do NOT commit or POST.

## Frontmatter contract

```yaml
---
id: MOD-<PROJECT>-<CODE>-<NNN>   # e.g. MOD-ECOM-CHK-001
slug: module-<name>
type: module-kb
project: <project>
module: <module-code>
status: accepted
owners:
  - <team>
created_at: <YYYY-MM-DD>
updated_at: <YYYY-MM-DD>
tags:
  - <tag>
related:
  - "[[<decision-slug>]]"
  - "[[<runbook-slug>]]"
retrieval_priority: 75
summary: <one line>
---
```

## Body template — the 9 standard sections (all REQUIRED)

```markdown
# Module KB: <name>

## Purpose
<Why this module exists. What business / technical outcome it delivers.>

## Responsibilities
- <bullet: each clear responsibility>

## Entry Points
- <bullet: how callers interact — HTTP routes, CLI, event handlers, messages>

## Main Flows
<Prose or ordered list describing the 2–5 most important flows.>

## Dependencies
- <bullet: other modules, external services, infrastructure>

## Data Contracts
- <bullet: DB tables it owns, message shapes, API contracts>

## Edge Cases
- <bullet: known failure modes, race conditions, cap/limit boundaries>

## Monitoring
- <bullet: key metrics, alerts, dashboards>

## Related Notes
- [[<decision-slug>]]
- [[<runbook-slug>]]

## Open Questions
- <anything missing — delete section if none>
```

## Vincoli

- Ogni sezione è obbligatoria. Se non hai info per una sezione,
  mettila vuota MA aggiungi il punto a "Open Questions".
- Non incollare dump di codice. Descrivi le interfacce, non l'implementazione.
- Non speculare. Se non hai visto una flow, non scriverla.
- Tono: documentazione tecnica operativa, non prosa da marketing.
