---
id: DEC-REFUND-002
slug: refund-policy
type: decision
status: accepted
retrieval_priority: 90
tags: [billing, refunds, policy]
owners: [finance-team]
summary: Current Northwind refund rules - full refund within 30 days of delivery, capped partial refund up to day 60.
related:
  - "[[order-lifecycle-concept]]"
  - "[[vat-rounding-standard]]"
---

# Refund Policy (current)

This decision defines when and how much Northwind Logistics refunds on a
returned order line. It replaced the previous rules in January 2024.

## Eligibility windows
- **Days 0-30 after delivery:** full refund of the line total.
- **Days 31-60 after delivery:** partial refund, capped at 50% of the line total.
- **Day 61 onwards:** no commercial refund. Statutory warranty claims
  (24 months for defective goods) follow a separate legal process.

## Approval
Any refund above EUR 500 needs a second approver from the finance team.
Refunds up to EUR 500 are approved by the support agent who owns the case.

## Payout

The money returns to the original payment method within 5 business days
of the approval. Shipping fees are refunded only when the refund is a
full one; partial refunds never include shipping.

## Credit notes

Every refund generates a credit note that follows the VAT rounding
standard ([[vat-rounding-standard]]). The order status moves to RETURNED
as described in the order lifecycle ([[order-lifecycle-concept]]).
