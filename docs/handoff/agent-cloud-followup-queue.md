# Cloud follow-up delivery investigation

Observed on 29 October 2026 in Surface / Ask My Docs / develop. All timestamps
below are UTC. The deployed revision was `e4c4a2610`.

## Incident and current state

The export `chat-debug-112-20261028-235028.json` was captured before the worker
received either follow-up. A later read-only query against the same environment
established their eventual outcome:

| Run | Created | Worker receipt | Queue wait | Outcome |
| --- | --- | --- | --- | --- |
| 238 | 28 Oct 23:20:03 | 23:20:04 | 449 ms | Partial answer saved, worker completed |
| 239 | 28 Oct 23:48:12 | 23:51:09 | 176,725 ms | Already cancelled at 23:50:11; handler returned without research |
| 240 | 28 Oct 23:50:17 | 23:51:09 | 51,904 ms | Partial answer saved at 23:52:13 |

All three recorded worker attempt 1. These observations establish delayed first
delivery, rather than a permanently stuck follow-up or repeated delivery.

Cloud's inspected default-queue logs in the 23:47–23:53 window show a managed
worker and Cloud agent starting at **23:51:07.203**, with the agent ready at
23:51:07.205. Application entry followed approximately two seconds later. These
records link delivery to that worker's startup; they do not census every worker
in the pool. The provider's underlying reason for the late delivery is not
available in the application spans or visible worker logs.

The queue was active, configured as Flex 512 MiB with a 0–25 worker range. At
inspection its pending, reserved and delayed counts were all zero. The daily
memory peak displayed by Cloud was 48%; this is not evidence of memory exhaustion.
The large historic failed-job list is separate from this incident; the latest
listed agent timeouts preceded these runs by several hours.

## Verification

Three new, conversation-free diagnostic runs were created already cancelled
before enqueueing, using the validated scope of run 238. The actual Cloud queue
and `ExecuteAgentRunJob` received each; the terminal handler path performed no
AI or tool requests. Each was enqueued only after the preceding worker span
completed. Existing conversations and answers were not changed.

Probe group: `7e221687-7b93-4e24-81d1-242e3e819e32`.

| Diagnostic run | Queue wait | Worker duration | Result |
| --- | --- | --- | --- |
| 241 | 622 ms | 350 ms | Completed worker span |
| 242 | 62 ms | 88 ms | Completed worker span |
| 243 | 69 ms | 86 ms | Completed worker span |

This proves current delivery of consecutive jobs. It does not establish the
same worker identity for all three or certify a particular cold-start condition.
Diagnostic runs remain terminal and are not attached to any conversation.

`tests/Feature/Agent/LongLivedAgentWorkerTest.php` exercises one real database
queue daemon across three consecutive turns, with the real application handler:

- First answer completed, then two newly enqueued follow-ups.
- First answer partial, then two newly enqueued follow-ups.
- Reasoning, waves and combined initial planning enabled: one KB search wave,
  followed by two context-only recap turns with persisted conversation reasoning
  context.

Assertions cover answers, terminal states, queue and worker spans, conversation
context, tenant/auth/locale/timezone/model-scope cleanup, released run locks and
empty job/failure tables. External providers and retrieval use fixtures. These
tests cover the application lifecycle; the live probes cover Cloud delivery.

## Operational correction applied

Marco explicitly authorized the continuously billed minimum of **$8.84/month**.
The Cloud preview for the resulting configuration was **$8.84–$220.92 per month**;
the existing maximum was unchanged.

The interactive `default` queue now has a minimum of **one** worker, retaining
Flex **512 MiB** and a maximum of **25**. This removes its dependency on allocating
the first worker after an idle period. Ingestion and connector queues were not
changed. No jobs were purged and no cancelled conversations or historic failed
jobs were replayed.

Cloud requires a deployment to activate this setting. Before deployment, the
review showed exactly one pending change: `default` autoscaling **0–25 → 1–25**.
The remote `develop` branch still pointed to the already deployed `e4c4a2610`.
[Deployment 165](https://cloud.laravel.com/surface/ask-my-docs/develop/deployments/165/e4c4a26)
completed successfully in 1m43s on that same revision, and its deployed-change
record confirmed the autoscaling change. No Git push was performed.

### Verification after deployment

Three more conversation-free diagnostic runs used the same terminal-only probe
procedure. Probe group: `810bcdaf-8105-4ad4-848f-231e379e198b`, at approximately
29 October 07:42:16–07:42:18 UTC.

| Diagnostic run | Queue wait | Worker duration | Result |
| --- | --- | --- | --- |
| 244 | 439 ms | 392 ms | Completed worker span |
| 245 | 66 ms | 93 ms | Completed worker span |
| 246 | 60 ms | 94 ms | Completed worker span |

All three consecutive deliveries passed, with zero AI or tool requests. The
application regression suite also passed: **471 tests, 2,644 assertions**,
including the three long-lived-worker cases. This suite was completed before
the infrastructure-only deployment; no application source changed afterward.

Cloud's [managed queue documentation](https://laravel.com/cloud/docs/queues)
describes independent worker pools, autoscaling floors and worker-time billing.
The observed 177-second startup delay exceeds its documented normal wake-up
behavior; retaining a worker avoids that startup dependency but is not proof
that the provider incident cannot recur.
