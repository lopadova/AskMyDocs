# On-call handover - week 18

Notes from the Monday 09:00 handover between the outgoing and incoming
on-call engineers. Handover happens every Monday at 09:00 CET.

## Last week

- No SEV1 incidents, one SEV3 caused by a slow report query.
- Four paging alerts, all acknowledged in time; two were noise from the
  new queue-depth alert.
- Non-paging alerts (Slack only) should be acknowledged within 30 minutes
  during business hours.

## Open items

- The queue-depth alert threshold is too sensitive; lower the paging
  condition to 15 minutes of sustained backlog.
- The label contract tests failed once on a carrier sandbox; the carrier
  has been contacted.
- Dashboard for canary error rates still lacks the per-region split.

## Rotation

The rotation is weekly. The secondary on-call covers the primary for lunch
breaks and for one night per week.
