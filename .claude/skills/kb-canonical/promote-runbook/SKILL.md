---
name: promote-runbook
description: 'CONSUMER-SIDE template — use in a consumer repository, NOT when editing AskMyDocs itself. Triggers when an incident, support escalation, or operational procedure emerges that should be captured as a canonical runbook Markdown (trigger / preconditions / steps / rollback / escalation). Draft only — never commits. Example triggers: turn this procedure into a runbook, document the incident response, here is how we recover from X.'
---

# Promote runbook (consumer-side template)

> **Banner:** template for consumer projects. Do NOT invoke when
> editing AskMyDocs itself.

## Goal

Produce a draft `runbook` canonical Markdown that operators can follow
under time pressure. Runbooks have the highest `retrieval_priority`
(90+) because they're load-bearing during incidents — the chatbot
should surface them immediately when a matching trigger arrives in a
question.

## Operating instructions

1. **Define the trigger precisely.** A runbook must answer "when do I
   open this doc?" in one sentence. Examples: "fast-checkout payment
   errors >1% over 5 min", "GDPR data export request received", "Redis
   cache hit rate drops below 60%".
2. **Enumerate preconditions + required access.** What systems must
   the operator be logged into? Which credentials / approvals?
3. **Write steps in strict order** with explicit verification after
   each step. If step N can fail, show the recovery path before step N+1.
4. **Add rollback.** Every runbook that performs a state change must
   describe how to reverse it if the change makes things worse.
5. **Add escalation.** When do we stop executing this runbook and
   page someone? Who?
6. **Link to related docs.** Decisions that drove the runbook design,
   module-kb docs for the affected subsystems, incidents that prompted
   it.
7. **Consider supersession.** If a previous runbook covered the same
   trigger, reference it in `supersedes:` — the new one will win in
   retrieval and the old one gets demoted.

## Frontmatter contract

```yaml
---
id: RUN-<YYYY>-<NNNN>
slug: runbook-<slug>
type: runbook
project: <project>
module: <module-code>
status: accepted
owners:
  - <sre-or-ops-team>
  - <domain-team>
created_at: <YYYY-MM-DD>
updated_at: <YYYY-MM-DD>
tags:
  - <tag>
related:
  - "[[<decision-slug>]]"
  - "[[<incident-slug>]]"
supersedes: []
retrieval_priority: 95
summary: <one line>
---
```

## Body template

```markdown
# Runbook: <title>

## Trigger
<One-sentence precise condition that opens this runbook.>

## Preconditions
- <required access / role>
- <required tool / dashboard>

## Immediate Actions
1. <step 1>
   - Verification: <what success looks like>
   - On failure: <go to step X, or escalate>
2. <step 2>
   - Verification:
   - On failure:
3. ...

## Verification Steps
- <final-state checks after all immediate actions>

## Rollback
<How to reverse the changes if the fix makes things worse.>

## Escalation
- <when to stop and page>
- <who to page>

## Post-Incident Notes
<Only fill after the runbook has been used. Leave a log of the real
execution — timings, surprises, what should be improved next time.>

## References
- [[<related-incident>]]
- [[<related-decision>]]
```

## Vincoli

- Ogni passo operativo DEVE avere una verifica esplicita. "Restart the
  service" da solo non basta — "Restart the service; verify health
  endpoint returns 200 within 30s".
- Niente prosa. Numbered list, verbi imperativi.
- Se il runbook supera 30 passi, splittalo.
- Ogni volta che il runbook viene eseguito in produzione, il
  Post-Incident Notes va aggiornato. Skill `link-kb-note` può aiutare.
