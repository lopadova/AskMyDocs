# Runbook — rolling out the retrieval-quality capabilities (ADR 0035)

**Audience:** operators. **Premise:** every capability below ships **default-OFF** and is
promoted only on evidence from `kb:benchmark --suite=v2` against a real stack (PostgreSQL +
pgvector + a real embeddings provider). The build environment of the cycle had none of these,
so no default was flipped there.

## 0. Prerequisites

```bash
php artisan migrate                       # 2026_11_05_000001..000004 (000004 = W7 learning tables)
php artisan kb:benchmark --suite=v2       # record the BASELINE scorecard (storage/app/kb-benchmark/)
```

Keep the baseline JSON: every step below is compared to it, per class, not only in aggregate.

Chat runs the recursive investigation when `KB_RECURSIVE_RETRIEVAL_ENABLED=true` (the default since
the main consolidation). Where a chat provider and a retrieval profile are configured, record a second
baseline on that path too — it is the path users actually get:

```bash
php artisan kb:benchmark --suite=v2 --path=investigation   # LIVE: interpreter + assessment model calls
```

The investigation's round queries are model-written, so **query expansion never runs on them**
(`meta.query_expansion.reason = model_written_query`). Expansion, the cross-encoder and the top-rank
bonus still act on every round's retrieval; only the expansion step is skipped.

## 1. One capability at a time

| Step | Enable | Then | Watch (per class) | Rollback |
|---|---|---|---|---|
| Websearch FTS syntax | `KB_HYBRID_SEARCH_ENABLED=true KB_FTS_QUERY_SYNTAX=websearch` | benchmark | `identifier`, `hard_negative` up; no class down | `KB_FTS_QUERY_SYNTAX=plain` |
| Contextual embeddings | `KB_EMBEDDING_CONTEXTUAL_INPUT=true` | `kb:reembed-stale --dry-run` (tokens/cost), `kb:reembed-stale`, drain the queue, benchmark | `context_dependent`, `paraphrase` up | unset; `kb:reembed-stale` again (the fingerprint reports the drift both ways) |
| Cross-encoder | `KB_CROSS_RERANK_ENABLED=true` (+ profile endpoint/allow-list) | benchmark | `paraphrase`, `hard_negative` up; **refusal accuracy must not drop** | unset (no data change) |
| RRF top-rank bonus (W6) | `KB_HYBRID_SEARCH_ENABLED=true KB_RRF_TOP_RANK_BONUS_ENABLED=true` (tune `_FIRST` 0.05 / `_PODIUM` 0.02) | benchmark | `identifier`, `hard_negative` up; `kb:search --explain` shows `rrf.top_rank_bonus` | unset (fusion byte-identical) |
| Query expansion | `KB_QUERY_EXPANSION_ENABLED=true` | benchmark | `paraphrase`, `short_ambiguous`; latency in `meta.query_expansion` | unset |
| Self-hosted expansion (W6, optional) | `KB_QUERY_EXPANSION_PROFILE=self_hosted` + `KB_QUERY_EXPANSION_SELF_HOSTED_URL`, `_MODEL`, `_ALLOWED_HOSTS` (+ `_API_KEY`, `_ALLOW_HTTP` for on-prem http, `KB_QUERY_EXPANSION_TIMEOUT`) | benchmark against the `provider` profile | same classes; `meta.query_expansion.status` stays `ok` (a host not allow-listed reads `unavailable`) | `KB_QUERY_EXPANSION_PROFILE=provider` |
| Code ingestion | `KB_CODE_INGESTION_ENABLED=true`, `npm install --prefix tools/code-chunker` | ingest a repository with `--pattern`, benchmark | `code` class | unset (existing code documents stay searchable; `kb:delete` removes them) |

Agent affordances (`KB_MCP_FACETS_ENABLED`, `KB_PATH_CONTEXTS_ENABLED`, and from W6
`KB_MULTI_GET_ENABLED`, `KB_QUERY_DOCUMENTS_ENABLED`) change what an agent *sees* or *sends*, not the
ranking of a plain query; enable them per deployment and check `kb:facets` / `kb:path-context list` /
`kb:multi-get '#<ref>'`. Short references (`document.ref`, `kb:show #ref`, `KbReadDocumentTool ref`)
need no flag.

- `KB_MULTI_GET_ENABLED=true` — `kb:multi-get`, `POST /api/kb/documents/multi`, `KbMultiGetTool`.
  Bounds: `KB_MULTI_GET_MAX_DOCUMENTS` (20), `KB_MULTI_GET_MAX_BYTES` (65 536),
  `KB_MULTI_GET_MAX_BYTES_CEILING` (262 144). OFF ⇒ `404 multi_get_disabled` / `{disabled: true}`.
- `KB_QUERY_DOCUMENTS_ENABLED=true` — a query with `lex:` / `vec:` / `hyde:` / `intent:` lines is used as
  its own expansion, without a model call (works with `KB_QUERY_EXPANSION_ENABLED=false`). Before turning
  it on, check that no client sends queries that legitimately begin with those words.

## 1b. The expansion learning loop (W7)

**Prerequisites:** PII redaction is active for the project (`pii-redactor.enabled`,
`kb.pii_redactor.enabled` and the project policy — without it the recorder stores **nothing**, by
design), and query expansion is ON and has served real traffic on the direct retrieval surfaces
(`kb:search`, the MCP search tools, the widget, chat with the investigation OFF). With the investigation
ON, chat turns do not expand and record nothing. With expansion OFF the gate always answers
`not_measurable`, so nothing can be auto-applied.

```bash
# 1. record (nothing else changes yet)
KB_EXPANSION_LEARNING_ENABLED=true
php artisan kb:expansion-learn status --project=hr            # events.total grows with traffic

# 2. propose — the nightly slot does it (kb_expansion_learn, 50 3 * * *); or by hand:
php artisan kb:expansion-learn analyze --project=hr           # runs the benchmark gate (minutes)
#    "Not enough expansion events" ⇒ below KB_EXPANSION_LEARNING_MIN_EVENTS (30) in the window (30 days)

# 3. read the proposal: weights, few_shot, evidence.types[*].hit_rate, gate.status
php artisan kb:expansion-learn status --project=hr
php artisan kb:expansion-learn apply  --id=<tuning>           # human decision, any gate result
php artisan kb:expansion-learn reject --id=<tuning>           # on an applied row = ROLLBACK to neutral
```

Read the gate before applying: `passed` (candidate ≥ baseline − `KB_EXPANSION_LEARNING_GATE_TOLERANCE` on
nDCG and MRR), `failed`, `not_measurable` (`query_expansion_disabled`, `benchmark_error`,
`partial_corpus`). The gate measures the **benchmark-v2 corpus**, not the project's documents — after
applying, compare `kb:search --explain` on a handful of real project queries. Admins can label events
(`POST /api/admin/kb/expansion-learning/events/{id}/feedback`, `value` −1 / 0 / 1); a harmful label
removes the event from examples and training. Only after several trustworthy gate results:
`KB_EXPANSION_LEARNING_AUTO_APPLY=true` (applies a proposal **only** on `passed`).

### Training a dedicated expansion model (optional)

```bash
# A. Self-hosted (no data leaves the deployment) — default backend
php artisan kb:expansion-learn train --project=hr --backend=export --yes
#    → JSONL on KB_EXPANSION_TRAINING_DISK under expansion-training/<tenant>/<uuid>.jsonl, status exported
#    → fine-tune outside the platform (Unsloth / TRL / Axolotl), serve it OpenAI-compatibly (vLLM, Ollama…)
#    → KB_QUERY_EXPANSION_PROFILE=self_hosted + _SELF_HOSTED_URL/_MODEL/_ALLOWED_HOSTS → benchmark → keep or revert

# B. OpenAI fine-tuning — ONLY for tenants with recorded consent + a DPA for this purpose
KB_EXPANSION_TRAINING_CONSENTED_TENANTS=acme
php artisan kb:expansion-learn train --tenant=acme --project=hr --backend=openai \
    --base-model=gpt-4.1-mini-2025-04-14 --yes                # submitted
php artisan kb:expansion-learn refresh --tenant=acme --id=<run>   # poll now (the nightly slot also polls)
php artisan kb:expansion-learn promote --tenant=acme --id=<run> --yes   # served via the OpenAI provider (ft:…)
php artisan kb:expansion-learn retire  --tenant=acme --id=<run>         # ROLLBACK
```

`retire` refuses a run still `submitted` / `training` at the provider — cancel the provider job first.
Every apply / reject / train / promote / retire leaves an `admin_command_audit` row
(`command = kb:expansion-learn <action>`). The gate's own benchmark corpus lives in the tenant
`KB_EXPANSION_LEARNING_GATE_TENANT` (`system-expansion-gate`); do not reuse that tenant for customers.

Rules the code enforces: at least `KB_EXPANSION_TRAINING_MIN_ROWS` (50, floor 10) rewarded examples; the
base model must be one of the code-owned allow-list; the API base must match
`KB_EXPANSION_TRAINING_OPENAI_BASE_URL` / `_ALLOWED_HOSTS`; over HTTP, `training` and `promote` need a
super-admin and `confirm: true`. A promoted model is used only while `KB_QUERY_EXPANSION_PROFILE=provider`
(the self-hosted profile always wins). Benchmark after promoting.

**Privacy.** Turning the loop on starts a new processing purpose (masked queries stored to improve
retrieval): update the record of processing and the PR's GDPR note. Retention is
`KB_EXPANSION_LEARNING_RETENTION_DAYS` (90; `kb:expansion-learn prune [--days=]`, also nightly).

## 2. Reading a regression

`kb:search "<query>" --explain` shows, per result, which lists produced it, the RRF
contributions (`rrf.base`, `rrf.top_rank_bonus`, `rrf.total`), the heuristic terms and — when the
stage ran — the cross-encoder score and the blend. Add `--investigation` to trace what chat actually
ran (round queries, `stop_reason`, selected documents; `status: disabled` when the switch is off),
`--line-numbers` to get each chunk's line range, `--min-score=` / `--all` to widen or cut the list, and
`--csv` / `--files` / `--md` for spreadsheets and pipes. A degraded stage is visible, never silent: `meta.cross_encoder.status = "unavailable"`,
`meta.query_expansion`, and for code `metadata.chunker` per chunk plus `code_chunker_ok` on the
health check.

## 3. Things that cannot be switched off

The code deny-list, size cap, binary/generated checks and secret redaction have no flag. If a
legitimate file is skipped, rename or relocate it (or request the rule change); do not look for a
switch. Credentials found in source are replaced by `[REDACTED_SECRET]` before storage and embedding —
rotate any credential that was committed, the redaction protects the index, not the repository.

## 4. Full rollback

Every flag above is independent and default-OFF; unsetting it restores the previous behaviour with no data
migration. The learning loop's state is additive: `reject` the applied tuning (neutral weights, no examples)
and `retire` a promoted model — or set `KB_EXPANSION_LEARNING_ENABLED=false`, which makes the live search
read the neutral tuning and no promoted model at once. The three W7 tables can stay; their rows age out
through the prune.
