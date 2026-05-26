---
id: REJ-CACHE-001
slug: rejected-clientside-caching
type: rejected_approach
status: accepted
retrieval_priority: 70
tags: [cache, rejected, client-side]
owners: [platform-team]
summary: Why AskMyDocs rejected a client-side-only caching approach (no server cache).
related:
  - "[[cache-invalidation]]"
---

# Rejected: Client-Side-Only Caching

We considered caching query results exclusively in the browser / API client
and keeping the server stateless, with no Redis layer at all.

## Why it was rejected

- **No cross-user reuse.** A client-side cache only benefits one user;
  popular queries are recomputed for every visitor, so the expensive
  vector + rerank work is never amortized across the tenant.
- **No central invalidation.** When a document changes there is no reliable
  way to purge every client's cache, so stale answers persist for hours.
- **Tenant isolation risk.** Pushing cached grounding context to the client
  widens the surface for cross-tenant leakage.

The accepted alternative is the server-side Redis strategy in the cache
invalidation decision ([[cache-invalidation]]). Do not re-propose
client-side-only caching.
