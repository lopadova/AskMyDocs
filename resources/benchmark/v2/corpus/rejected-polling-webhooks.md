---
id: REJ-API-002
slug: rejected-polling-webhooks
type: rejected-approach
status: accepted
retrieval_priority: 70
tags: [api, carriers, polling, rejected]
owners: [api-platform-team]
summary: Why Northwind rejected polling carrier APIs for shipment status instead of consuming push webhooks.
related:
  - "[[webhook-signing-standard]]"
---

# Rejected: Polling Carriers for Shipment Status

We considered asking every carrier for the status of each open shipment on
a fixed timer instead of receiving pushed events.

## Why it was rejected

- **Call volume.** With 40,000 open shipments polled once a minute we would
  issue 2,400,000 calls per hour, far beyond the carrier contracts.
- **Latency.** Average status delay would be 30 seconds, against under
  5 seconds with push delivery.
- **Cost of waste.** More than 95% of polls return an unchanged status.

The accepted alternative is signed push delivery as defined in the webhook
signing standard ([[webhook-signing-standard]]). Do not re-propose timer
based polling of carriers.
