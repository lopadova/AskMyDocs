# Public API changelog - 2026

Release notes for the Northwind public API. Dates are release dates.

## v2.3.0 - January 2026

- Cursor-based pagination on the orders collection.
- The `fields` parameter limits the attributes returned by list calls.

## v2.4.0 - April 2026

- New `include=tracking` parameter on the order resource.
- The idempotency cache window is raised from 6 to 24 hours.
- Webhook payloads gain a `version` field.

## v2.5.0 - July 2026

- Bulk order import accepts up to 500 items per request.
- Offset pagination is deprecated; removal is planned for January 2027.
- Rate limit headers are unchanged.

## Deprecation policy

A deprecated feature is announced in the changelog at least 6 months before
removal and flagged with a `Sunset` response header.
