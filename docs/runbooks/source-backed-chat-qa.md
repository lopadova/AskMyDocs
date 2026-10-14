# Source-backed local chat QA

Use `scripts/qa/agent-kb-probe.php` to replay private, source-backed questions
through the real durable chat pipeline in the local application. It creates a
separate conversation per case and records run IDs, answers, tool executions,
events, the code revision and a probabilistic Jev review. These are real calls
to the configured AI providers, not mocked unit tests.

Keep the case file, original messages and result directory outside Git, for
example under `storage/app/private/`. Never commit provider credentials or
customer correspondence.

```sh
php scripts/qa/agent-kb-probe.php \
  --cases=storage/app/private/audit/cases.json \
  --output=storage/app/private/audit/results \
  --round=baseline
```

The case file contains `tenant_id`, `project_key`, `actor_id`, `locale`,
`timezone` and a `cases` array. Each case declares `id`, `document_ids`,
`question`, `expected` and optionally `depth`, `filters` and `live_sources`.
Omitted live sources default to empty API/MCP allowlists so document tests do
not accidentally query unrelated live systems. To test an authorized API,
select its catalog source key explicitly, for example
`"live_sources": {"api": ["api:4"], "mcp": []}`.

Only the question, filters and selected live sources enter the agent input.
The expected facts and reference originals are reserved for the independent
review. IMAP parent references use the verified complete historical body from
`TriageCampaigns::body()`, including the immutable original after triage. They
exclude the generated triage card, so classification does not validate itself.
Freshdesk references retain their verified case-study lineage.

Run the exact same cases under a new round after a fix. New conversations avoid
learning the earlier answer. `--reopen=baseline` deliberately tests continuing
the previous conversation instead. Existing result files are never overwritten;
use `--only=case-id` for targeted diagnosis. Restore tenant/project scope and
resolve current document versions when retrospective triage has archived an
earlier document row.

Jev is a semantic review aid, not proof of correctness. Inspect answers against
their original sources, check exact identifiers independently, and keep missing
facts distinct from retrieval failures. Provider unavailability, unavailable
originals and unexecuted cases must not be counted as passing. The runner stops
on provider authentication or credit errors rather than recording further
apparently successful empty answers.

See [email triage](../email-triage.md) for the existing retrospective campaign
that reads complete originals, pins a profile revision and reindexes the same
logical email while preserving access and manual tags.
