---
name: session-close
description: 'CONSUMER-SIDE template — use in a consumer repository, NOT when editing AskMyDocs itself. Triggers at the end of a work session (editorial sprint, incident post-mortem, design discussion, code review wrap-up) to summarize what happened and surface candidate canonical knowledge artifacts for subsequent promotion via promote-decision / promote-module-kb / promote-runbook skills. Writes nothing — only produces a structured shortlist for human review. Example triggers: session wrap-up, close the session, what should we promote from this.'
---

# Session close (consumer-side template)

> **Banner:** template for consumer projects. Do NOT invoke when
> editing AskMyDocs itself.

## Goal

At the end of a session, review what happened and produce:

1. A concise summary (what was done, decided, learned, rejected, left open).
2. A structured list of **candidate knowledge artifacts** that should be
   promoted to the canonical KB via a follow-up skill run.

This skill is the **funnel** into the promotion pipeline. It writes
nothing — it surfaces signal.

## Operating instructions

1. **Summarize the session** in ≤ 200 words under 5 headings:
   - **Done** — concrete actions taken / work shipped.
   - **Decided** — choices made.
   - **Learned** — non-obvious findings, gotchas, verified assumptions.
   - **Rejected** — options considered and dismissed (feeds the
     anti-repetition memory via the `rejected-approach` type).
   - **Open** — questions / follow-ups.
2. **List candidate artifacts** — for each item in "Decided", "Learned",
   or "Rejected" that you think is worth canonicalizing, output:
   ```
   - type: <decision | module-kb update | runbook | rejected-approach | standard>
     proposed_slug: <kebab-case>
     proposed_title: <short title>
     reason: <why this is promotion-worthy — 1 line>
     related: [<existing-slugs the author mentioned>]
     follow_up_skill: <promote-decision | promote-module-kb | promote-runbook | link-kb-note>
   ```
   Quality over quantity. If nothing in the session merits promotion,
   output an empty list — do not invent artifacts to fill the slate.
3. **Do NOT write canonical files.** The candidates are prompts for
   the human, who will then run the matching `promote-*` skill on the
   ones that survive review.

## Output format

```markdown
# Session summary — <date>

## Done
- <bullet>

## Decided
- <bullet>

## Learned
- <bullet>

## Rejected
- <bullet>

## Open
- <bullet>

---

## Candidate knowledge artifacts

1. type: decision
   proposed_slug: dec-cache-invalidation-v2
   proposed_title: Cache invalidation v2
   reason: The team converged on tag-based invalidation after two rounds of discussion.
   related: [[module-cache-layer]]
   follow_up_skill: promote-decision

2. type: rejected-approach
   proposed_slug: rejected-full-purge-on-price-change
   proposed_title: Full cache purge on price change
   reason: Dismissed explicitly as "too expensive and noisy CDN-side".
   related: [[dec-cache-invalidation-v2]]
   follow_up_skill: promote-decision   # uses rejected-approach type

...or empty:

## Candidate knowledge artifacts

_(none this session)_
```

## Vincoli

- Quantità minima di promozioni va bene — preferisci 0 candidati
  mediocri a 3 candidati che verranno rigettati in review.
- Ogni candidato deve avere `reason` e `related`, anche se related
  è `[]`. Niente entrate placeholder.
- NON richiamare le skill `promote-*` direttamente. Produci solo la
  lista — il human decide cosa promuovere.
- Se la sessione non aveva contenuto rilevante (es. pure debugging
  di un typo), marcalo esplicitamente come `no promotion-worthy
  artifacts` e non forzare candidature.
