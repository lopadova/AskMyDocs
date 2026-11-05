---
id: INC-2026-0412
slug: incident-2026-04-label-outage
type: incident
status: accepted
retrieval_priority: 75
tags: [incident, labels, carriers, postmortem]
owners: [sre-team]
summary: Postmortem of the 12 April 2026 shipping label outage that delayed 2,150 orders.
related:
  - "[[shipment-labels-api]]"
  - "[[incident-escalation-runbook]]"
---

# Postmortem INC-2026-0412 - Shipping label outage

## Summary

On 12 April 2026 label generation failed for 3 hours 40 minutes. A total of
2,150 orders left the warehouse up to one day late.

## Root cause

The carrier Aurora Express renamed the `zpl_data` field in its response
without notice. Our parser treated the missing field as an empty label and
returned an error to the warehouse stations.

## Timeline

- 09:05 UTC: first failed label requests.
- 09:27 UTC: alert fired, 22 minutes after the first failure.
- 11:10 UTC: workaround releases accepting both field names.
- 12:45 UTC: backlog fully printed.

## Follow-ups

- Nightly contract tests against every carrier sandbox.
- Alert threshold lowered from 5% to 1% failed label requests.
- Process followed: [[incident-escalation-runbook]]. Affected integration:
  [[shipment-labels-api]].
