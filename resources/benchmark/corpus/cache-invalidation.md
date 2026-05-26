---
id: DEC-CACHE-001
slug: cache-invalidation
type: decision
status: accepted
retrieval_priority: 90
tags: [cache, redis, invalidation]
owners: [platform-team]
summary: How AskMyDocs invalidates cached data using Redis TTL plus event-based purge.
related:
  - "[[cache-purge-runbook]]"
---

# Cache Invalidation Strategy

AskMyDocs caches read-heavy query results in Redis. Our invalidation
strategy combines two mechanisms so stale data is never served for long.

## Time-to-live (TTL)

Every cached entry carries a default TTL of 300 seconds. Short-lived
volatile data (search facets, dashboard counters) uses a 60 second TTL.
The TTL is a safety net: even if an explicit purge is missed, an entry
self-expires within five minutes.

## Event-based purge

When a knowledge document is ingested, updated, or deleted, the ingestion
pipeline emits a `kb.document.changed` event. A listener purges every
Redis key tagged with the affected `project_key` and `tenant_id`. This is
the primary path and gives sub-second freshness on writes.

## Why both

TTL alone is too slow for write-heavy tenants; event purge alone is fragile
if a worker crashes mid-purge. Together they bound staleness to whichever
fires first. For the recovery procedure when the cache layer itself fails,
see the cache purge runbook ([[cache-purge-runbook]]).
