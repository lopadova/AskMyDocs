---
id: RUN-INC-009
slug: incident-escalation-runbook
type: runbook
status: accepted
retrieval_priority: 85
tags: [incident, escalation, oncall, sev]
owners: [sre-team]
summary: Escalation steps and acknowledgement targets for SEV1, SEV2 and SEV3 incidents.
related:
  - "[[incident-2026-04-label-outage]]"
---

# Incident Escalation Runbook

Use this runbook as soon as monitoring or a human reports a production issue.

## SEV1 - platform down
Acknowledge within 5 minutes. Page the primary on-call engineer with
`nwctl page --sev 1`. An incident commander is assigned within 10 minutes.
The status page is updated within 15 minutes and every 30 minutes after.
If the incident is unresolved after 30 minutes, escalate to the CTO.

## SEV2 - major degradation
Acknowledge within 15 minutes. Status updates go out every 60 minutes.
Escalate to the engineering manager after 2 hours.

## SEV3 - minor issue
Acknowledge by the next business day. No page is sent; the ticket goes to
the owning team queue.

## War room

For SEV1 and SEV2 open the Slack channel `#inc-war-room` and invite the
incident commander, the scribe and the owning team lead.

## After the incident

A written postmortem is due within 5 business days. A worked example is
[[incident-2026-04-label-outage]].
