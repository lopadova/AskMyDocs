# Rule: untrusted input, external boundaries and protected data

## Identifiers

`SEC-DESERIALIZE-001`, `SEC-PATH-001`, `SEC-UPLOAD-001`, `SEC-SHELL-001`,
`SEC-SSRF-001`, `SEC-XML-001`, `SEC-EXTRESP-001`, `SEC-WEBHOOK-001`,
`ARCH-HTTP-001`, `SEC-AUDIT-001`, `SEC-LOG-001`, `SEC-PII-CRYPT-001`,
`SEC-KEYS-001`, `SEC-APPKEY-001`, `SEC-GDPR-001`, `SEC-DUMP-001`,
`SEC-RETENTION-001`, `SEC-SIEM-001`, `SEC-FESECRET-001`, `SEC-CSV-001`.

## Input and outbound controls

- Deserialize only authenticated, schema-versioned payloads with explicit allowed
  types. Never `unserialize` or decrypt attacker-controlled HTTP input and then
  rely on a post-hoc class check.
- Resolve filesystem targets against an approved root and verify containment after
  normalization/symlink resolution. Filenames never decide executable paths.
- Uploads enforce size/count, decoded type and magic bytes, extension policy,
  image decode/re-encode where applicable, non-public storage and randomized names.
  SVG/XML and archive bombs need dedicated handling; base64 syntax is not file validation.
- Shell/process calls use argument arrays and fixed executable/operation allow-lists;
  no concatenated user/model data or shell interpolation.
- Outbound URLs use scheme+host+port allow-lists, DNS/IP checks against private,
  metadata and loopback ranges, redirect re-validation, timeouts and response-size
  caps. Use one hardened client abstraction or an explicitly inventoried SDK.
- XML parsing disables external entities/network access and limits size/depth.
- Webhook authenticity, timestamp/replay and body integrity are verified before
  parsing or any side effect. Invalid events are audited without raw secrets.
- External responses validate status, content type, bounded body and schema before
  use; unexpected data fails loudly and safely.
- External calls use per-service circuit-breaker identity at a mandatory choke point.
  Count network/5xx failures, not valid 4xx responses; cache failure behavior and
  exclusions are explicit and observable, never an accidental global outage.

## Data protection

- Classify personal, confidential and authentication data at collection. Minimize
  prompts, logs, exports and persisted snapshots; document retention and erasure.
- Record consent notice version/hash without inventing defaults. Data-access and
  erasure inventories derive from the same data map and use export allow-lists.
  Every third-party processor receiving personal data declares purpose, categories,
  residency/DPA status and code indicators; stale and missing registrations surface.
- Encrypt sensitive fields with managed, rotatable keys and separated duties.
  Secrets live server-side only and never in frontend bundles, source, prompts,
  logs, telemetry, URLs or exception responses.
- Security audit records are append-oriented, attributable, time-correlated,
  access-controlled and retained independently from short-lived replay/debug logs.
- Correlation IDs are format-allow-listed, shared across logs and returned to callers,
  but never authorize. Real on-disk retention is measured against every pruning job;
  security events are forwarded off-host at least once with downstream deduplication.
- Development copies of production data carry a verifiable anonymization marker;
  zero sampled rows is non-verifiable, not success. The verifier refuses production.
- Every `APP_KEY` dependency (Laravel encryption versus direct HMAC) is inventoried
  with rotation impact, previous-key coverage and orphan/undeclared detection.
- CSV/spreadsheet exports neutralize formula-leading cells while preserving data.
- Error responses expose stable public codes plus a correlation ID; SQL, bindings,
  stack traces, provider bodies and internal paths stay server-side and redacted.

## Review method

Trace source to final sink. A sanitizer at one controller is not coverage when a
job, MCP tool, stream, import or widget path can reach the same sink.
