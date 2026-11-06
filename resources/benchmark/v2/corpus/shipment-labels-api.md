---
id: INT-SHIP-011
slug: shipment-labels-api
type: integration
status: accepted
retrieval_priority: 80
tags: [api, carriers, labels, shipping]
owners: [fulfilment-platform-team]
summary: Integration contract for generating carrier shipping labels through the Northwind API.
related:
  - "[[webhook-signing-standard]]"
  - "[[api-rate-limits]]"
---

# Shipment Labels API

Generates a printable carrier label for a shipment that is ready to dispatch.

## Endpoint
`POST /v2/shipments/{id}/labels` creates a label. The `Idempotency-Key`
header is mandatory; a replay with the same key returns the original label.

## Formats
- `pdf` (default): A6 page, ready for office printers.
- `zpl`: 203 dpi, for thermal printers on the packing stations.

## Limits

A shipment can have at most 3 labels. The p95 latency target is 800 ms.
The endpoint is subject to the platform limits in [[api-rate-limits]].

## Carriers

Supported carriers are Aurora Express and BlueRoute. The carrier is chosen
by the routing rules of the shipment, never by the caller.

## Errors and events

HTTP 409 is returned when the shipment is already dispatched. Status
changes are pushed as signed webhooks ([[webhook-signing-standard]]).
