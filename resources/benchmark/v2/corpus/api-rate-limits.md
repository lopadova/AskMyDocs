---
id: STD-API-003
slug: api-rate-limits
type: standard
status: accepted
retrieval_priority: 85
tags: [api, rate-limit, platform]
owners: [api-platform-team]
summary: Current public API rate limits - 300 requests per minute standard, 1200 enterprise, with burst allowance.
related:
  - "[[shipment-labels-api]]"
  - "[[webhook-signing-standard]]"
---

# API Rate Limits

Applies to every request made against the Northwind public API with a
production API key.

## Standard tier
The limit is 300 requests per minute per API key. Short bursts of up to
600 requests in any 10 second window are tolerated.

## Enterprise tier
The limit is 1200 requests per minute per API key, with bursts of up to
2400 requests in any 10 second window.

## Counting rules

Bulk endpoints count one request for every 25 items in the payload.
Idempotent replays served from the idempotency cache are not counted.

## Errors and headers

When the limit is exceeded the API answers HTTP 429 with the error code
`ERR_RATE_429` and a `Retry-After` header expressed in seconds. Every
response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`.

Clients should back off exponentially, starting at 1 second and capping
at 32 seconds.
