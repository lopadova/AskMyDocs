# Finance sync - 14 May 2026

Attendees: Head of Finance, two accountants, the support operations lead.

## Agenda

1. Duplicate payout review
2. Quarter close calendar
3. Open items from the gateway migration

## REF-2291

A customer received two refunds of EUR 1,240 for the same order line.
The cause was a retry in the payment gateway client that did not reuse the
idempotency key. The second payout was reversed on 12 May. A fix that
persists the key before the first attempt shipped on 13 May.
Decision: finance reviews every refund above the approval threshold once
a week until the fix has run for a full month.

## Quarter close

- The Q2 VAT return is due on 30 June.
- Accountants freeze manual journal entries on 26 June.
- Support must close all open credit note requests by 24 June.

## Action items

- Accountant A: send the reversal evidence to the gateway by Friday.
- Support lead: add the duplicate check to the refund checklist.
- Head of Finance: report back at the next sync on 28 May.
