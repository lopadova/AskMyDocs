# Local QA inference ledger

The ledger is disabled unless `ASKMYDOCS_QA_MANIFEST` points to an existing
JSON file below `storage/app/private/qa/`. It is allowed only in local/testing
environments. Set `ASKMYDOCS_QA_CASE` and `ASKMYDOCS_QA_STAGE` for attribution;
isolated research processes inherit these environment variables.

A manifest declares `budget_usd`, `max_calls` and `allowed_models`, keyed by
the exact requested model. Each model declares `paths`, `input_per_million`,
`output_per_million` and `output_reserve_tokens`. For chat models, explicitly
list verified aliases in `response_models`. Set `enforce_provider_prices=true`
to require OpenRouter price sorting, supported parameters and provider price
ceilings no higher than the declared tariffs.

Before each inference POST, the ledger validates the OpenRouter host, path
and model and reserves a conservative amount under a shared file lock. It
includes every configured paid stage: text, typed decisions and embeddings.
Unapproved calls or exhausted reservations stop before dispatch. The companion
`inference-ledger.json` records case, stage, process, request hash, native
usage, actual response model, status, duration and provider-reported USD cost.
Prompt bodies are not stored. Keep the manifest and ledger private.

If a successful response omits cost, a read-only generation lookup may obtain
it. Errors, unknown billing, unexpected response models or a charge above the
reservation stop further paid calls. Unknown cost is stored as `null`; the
ledger subtotal must never be described as a complete total while any call is
unknown or pending. Reservations are estimates, not a provider-side account
spending cap. Provider retries are separate metered attempts.

This opt-in guard does not run a suite, authorize expenditure, alter evaluator
thresholds or replace provider authentication. A new paid run still requires
the user's authorization and a frozen corpus, rubric and code revision.
