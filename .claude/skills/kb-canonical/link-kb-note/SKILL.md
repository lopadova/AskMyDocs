---
name: link-kb-note
description: 'CONSUMER-SIDE template — use in a consumer repository, NOT when editing AskMyDocs itself. Triggers when existing canonical notes need to be enriched with explicit wikilinks or frontmatter relations so the knowledge graph reflects actual dependencies. Draft only — produces proposed wikilink additions to existing documents for human review, never commits. Example triggers: connect these documents, what else relates to X, link the new decision to the modules it affects.'
---

# Link KB note (consumer-side template)

> **Banner:** template for consumer projects. Do NOT invoke when
> editing AskMyDocs itself.

## Goal

Strengthen the canonical knowledge graph by adding high-quality wikilinks
between existing documents. `[[wikilinks]]` in the body become
`kb_edges` with `provenance='wikilink'`; frontmatter `related` /
`supersedes` entries become explicit edges. Better graph connectivity
= better retrieval (the graph expander walks 1 hop from the primary
result set).

**Quality over quantity.** A few strong links beat many weak links.

## Operating instructions

1. **Pick a target doc.** The user points at one canonical doc, or
   you identify one from session context. Open it, read the
   frontmatter + body.
2. **Enumerate candidate links** from:
   - Other docs in the same project — use `kb.documents.by_type` to
     list all decisions, modules, runbooks, etc.
   - Explicit references already in the body ("see the checkout
     module") that aren't yet wikilinked.
   - Frontmatter cues — if two docs share tags like `cache` +
     `invalidation`, they may deserve a link.
3. **Filter aggressively.** A link must add retrieval value. Red
   flags to REJECT:
   - Tangential relations ("both mention Redis") without a strong
     semantic tie.
   - Decorative links (a runbook doesn't need to wikilink every
     decision in the project).
   - Duplicates (the target slug already appears in `related:`).
4. **Prefer canonical frontmatter fields over inline wikilinks** for
   structural relations:
   - Decisions that SUPERSEDE → `supersedes: [[old-slug]]`
   - Design docs that DEPEND on a decision → `related: [[dec-slug]]`
   - Use inline `[[slug]]` in body only for textual context
     ("see also [[module-cache]]").
5. **Produce a patch**, not a rewrite. Show the old + new frontmatter
   + body diff only for the sections that change. Explain WHY each
   link is added ("links decision to the module it affects, so a chat
   about the module surfaces the decision via 1-hop graph expansion").
6. **Do NOT commit or POST.**

## Output format

```markdown
# Proposed link additions for: <slug>

## Frontmatter changes

### `related:` — adding 2, removing 0
- + [[<new-slug-1>]]  — <why>
- + [[<new-slug-2>]]  — <why>

### `supersedes:` — no change

## Body changes

### Section "<name>"
Old:
> <existing paragraph>

New:
> <existing paragraph with inline [[wikilink]] added>

Rationale: <why this link improves retrieval / navigation>.
```

## Vincoli

- Ogni link aggiunto DEVE avere una giustificazione in 1-2 righe.
- Non aggiungere un link se il target slug non esiste — piuttosto
  proponi di canonicalizzare quel target prima (e invocare
  `promote-decision` / `promote-module-kb` / ecc.).
- Non alterare il tono o il contenuto sostanziale del documento —
  questa skill aggiunge connettività, NON riscrive.
- Se ti trovi a proporre più di 5 link in un solo patch, ferma e
  chiedi all'umano se il documento non abbia bisogno di essere splittato.
