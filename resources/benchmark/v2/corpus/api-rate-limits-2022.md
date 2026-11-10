---
id: STD-API-001
slug: api-rate-limits-2022
type: standard
status: superseded
retrieval_priority: 20
tags: [api, rate-limit, platform, legacy]
owners: [api-platform-team]
summary: Superseded 2022 API rate limits - 60 requests per minute standard, 240 enterprise. Replaced by STD-API-003.
related:
  - "[[api-rate-limits]]"
---

# API Rate Limits (2022 edition, superseded)

> Retired in February 2024 and replaced by STD-API-003.

Applied to every request made against the Northwind public API with a
production API key.

## Standard tier

The limit is 60 requests per minute per API key. Short bursts of up to
120 requests in any 10 second window were tolerated.

## Enterprise tier

The limit is 240 requests per minute per API key, with bursts of up to
480 requests in any 10 second window.

## Counting rules

Bulk endpoints counted one request for every item in the payload.

## Errors and headers

When the limit was exceeded the API answered HTTP 429 Too Many Requests
with the plain body `rate limit exceeded` and a `Retry-After` header.
Every response carried `X-RateLimit-Limit` and `X-RateLimit-Remaining`.
