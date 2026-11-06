---
id: DEC-REL-010
slug: release-train-decision
type: decision
status: accepted
retrieval_priority: 80
tags: [release, deploy, canary, train]
owners: [release-engineering]
summary: Weekly release train every Tuesday at 10:00 CET with a 5% canary and automatic rollback.
related:
  - "[[incident-escalation-runbook]]"
---

# Release Train Decision

Production releases follow a fixed weekly train instead of ad hoc deploys.

## Schedule

The train departs every Tuesday at 10:00 CET. The code freeze starts on
Monday at 18:00; anything not merged by then waits for the next train.

## Canary

Each release first serves 5% of traffic for 30 minutes. The release is
rolled back automatically if the error rate exceeds 2% over any 5 minute
window.

## Hotfixes

A hotfix may ship any weekday outside the train with two approvals. Nothing
is deployed on Fridays unless a SEV1 is open; see
[[incident-escalation-runbook]].

## Ownership

The release engineer on duty owns the train, posts the go/no-go in the
release channel on Tuesday at 09:30 CET and closes the train in the tracker.
