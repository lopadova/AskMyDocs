# Sandbox environment guide

The sandbox lets integrators test API calls without touching real orders.

## Base URL

All sandbox calls go to `https://sandbox.api.northwind.example`. Requests
sent to this host never reach carriers or customers.

## Sandbox keys

Keys expire after 7 days. Sandbox keys cannot be rotated: request a new key
from the developer portal when one expires.

## Limits

The sandbox accepts 30 requests per minute per key, regardless of the plan
of the production account.

## Test data

The sandbox database is reset every Sunday at 03:00 UTC, so orders and
shipments created during the week disappear.

## Webhooks

Webhooks are delivered with a test secret and are signed exactly like in
production, which lets you verify your signature code end to end.

## Differences from production

Carrier calls are simulated and always return a label in under a second.
