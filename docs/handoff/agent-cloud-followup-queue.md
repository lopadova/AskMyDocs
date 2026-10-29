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

## Operational correction

Keep at least one worker available for the interactive `default` queue to remove
its dependency on allocating a first worker after idle periods. Preserve the
existing 512 MiB size and maximum of 25; no increase to downstream concurrency
limits is required. Leave the ingestion/connector queues' settings separate.

The unsaved Cloud preview for this exact configuration shows **$8.84–$220.92 per
month**, replacing the previous maximum-only estimate of $220.92. The minimum is
new; the existing maximum is unchanged. Confirm user authorization for the
continuously billed minimum before saving. At the time of this note, no Cloud
resource or autoscaling setting has been changed. Once authorized,
save the minimum of one, verify the persisted range, then repeat three sequential
delivery probes. Do not purge jobs, replay cancelled conversations, or retry the
historic failed-job backlog.

Cloud's [managed queue documentation](https://laravel.com/cloud/docs/queues)
describes independent worker pools, autoscaling floors and worker-time billing.
The observed 177-second startup delay exceeds its documented normal wake-up
behavior; retaining a worker avoids that startup dependency but is not proof
that the provider incident cannot recur.
