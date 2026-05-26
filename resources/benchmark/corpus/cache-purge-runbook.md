---
id: RUN-CACHE-001
slug: cache-purge-runbook
type: runbook
status: accepted
retrieval_priority: 80
tags: [cache, redis, incident, runbook]
owners: [platform-team]
summary: Step-by-step recovery when the Redis cache layer is stale, full, or unreachable.
related:
  - "[[cache-invalidation]]"
---

# Cache Purge Runbook

Use this runbook when the cache layer misbehaves: serving stale answers,
filling memory, or refusing connections.

## 1. Confirm the symptom

Check the cache hit-rate dashboard. A hit-rate above 99% combined with
user reports of stale answers points at a missed purge. Connection errors
in the application log point at an unreachable Redis.

## 2. Manual purge

Run `php artisan kb:cache-purge --project=<key>` to drop every cached key
for one project. For a full flush across all tenants, run
`php artisan kb:cache-purge --all`; expect a brief latency spike while the
cache re-warms.

## 3. Unreachable Redis

If Redis refuses connections, the application degrades gracefully: reads
fall through to PostgreSQL and answers are still correct, only slower.
Restart the Redis service, then re-warm with `kb:cache-warm`.

The underlying invalidation design this runbook recovers is described in
the cache invalidation decision ([[cache-invalidation]]).
