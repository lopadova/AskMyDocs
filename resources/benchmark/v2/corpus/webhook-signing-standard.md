---
id: STD-API-006
slug: webhook-signing-standard
type: standard
status: accepted
retrieval_priority: 85
tags: [api, webhook, hmac, security]
owners: [api-platform-team]
summary: How Northwind signs outgoing webhooks - HMAC-SHA256 over timestamp and body, 300 second replay tolerance.
related:
  - "[[shipment-labels-api]]"
---

# Webhook Signing Standard

Every webhook Northwind sends to a customer endpoint is signed so that the
receiver can prove authenticity and reject replays.

## Signature scheme
The signature is an HMAC-SHA256 computed over the string
`<timestamp>.<raw body>` with the endpoint secret. It is delivered in the
header `X-Northwind-Signature: t=<unix seconds>,v1=<hex digest>`.

## Replay protection
Receivers must reject a request when the timestamp differs from their own
clock by more than 300 seconds, answering HTTP 400.

## Comparison
The digest must be compared in constant time. A plain string equality check
leaks timing information and is not allowed in reference code.

## Secret rotation
Endpoint secrets rotate every 90 days. For 24 hours after a rotation both
the old and the new secret are accepted.

## Delivery retries

Failed deliveries are retried after 1 minute, 5 minutes, 30 minutes, 2 hours
and 12 hours. After the fifth failure the event is moved to a dead letter
queue. Label events are described in [[shipment-labels-api]].
