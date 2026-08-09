# Rule: production runtime and browser boundary

## Identifiers

`SEC-ENV-001`, `SEC-CORS-001`, `SEC-CSP-001`, `SEC-TLS-001`,
`SEC-CLIENTIP-001`, `SEC-THROTTLE-001`, `SEC-REDIS-001`, `SEC-DBPRIV-001`,
`SEC-LIMITS-001`, `SEC-SETTING-SHAPE-001`, `SEC-SETTINGS-SECRET-001`,
`SEC-FESECRET-001`, `SEC-CSP-REPORT-001`, `SEC-ESPOSTI-001`,
`SEC-POSTURA-001`.

## Mandatory controls

- Production fails closed on unsafe configuration: debug/trace/test routes, fake
  providers, permissive auth, insecure cookies, missing encryption/signing keys and
  invalid security setting shapes. CI/deploy checks prevent release; a scheduled
  runtime audit detects drift without replacing the deploy gate.
- Credentialed CORS uses an exact normalized origin allow-list and emits one matching
  origin plus `Vary: Origin`; never reflect arbitrary origins and never combine
  wildcard origins with credentials. Enforce at edge where present and again in
  Laravel as defense in depth; duplicate layers must share/test the same policy.
- CSP is nonce/hash based where feasible and covers every served surface. No broad
  `unsafe-inline`, `unsafe-eval`, wildcard script/connect/frame sources or user data
  interpolated into directives.
- A CSP nonce is fresh per response and identical in header/body; full-page caches
  store an unguessable placeholder and materialize a fresh nonce on hit/miss/refresh.
  Enforcement follows report-only inventory through a throttled, size/field-bounded,
  control-character-safe collector that returns 404 when disabled.
- `postMessage` checks exact inbound `event.origin` before reading data and uses an
  exact outbound `targetOrigin`, never `*` or substring matching. Message data is
  schema-validated before any widget/Tauri/browser effect.
- HTTPS/HSTS/cookie security and trusted-proxy handling are environment-aware and
  tested. Client IP comes only from known proxies; spoofable forwarding headers do
  not drive auth, throttling or audit identity.
- Verify actual response headers, not merely configuration: CSP, HSTS,
  `Permissions-Policy`, anti-sniffing, framing and referrer policy have exact names
  and syntax. Document which layer emits each header and reject conflicting duplicates.
- Rate/resource limits are keyed by the effective identity and tenant where relevant,
  not solely by IP. Cap request/upload/body sizes, pagination, exports, RAG result
  counts, AI loops, queues and memory-heavy parsing.
- Redis and database use TLS/auth/network restriction, least-privilege production
  identities and separate administrative/migration credentials. Application roles
  cannot create extensions/schemas or bypass tenant policy.
- Security settings have a single canonical type/shape, protective defaults and
  explicit disable switches; malformed or missing values cannot coerce to permissive.
  Secret-like values are named, stored and displayed as secrets, never generic settings.
- Production posture reads raw environment for debug/profiler gates and treats unknown
  environments as production. Cached config divergence is blocking; staging receives
  production-grade secret/PII protections.
- `public/` uses a positive inventory of legitimate assets. Dumps, exports, uploads,
  backups and extensionless files are not anonymously served; runtime directories use
  authenticated delivery or signed, expiring object access.

## Surface applicability

CORS is a browser/server concern. It protects the web SPA and embedded widget, and
any desktop/mobile webview that sends browser credentials. Native Tauri/mobile
HTTP clients do not enforce browser CORS, so they require TLS, token, origin-independent
authorization and certificate/secret controls instead; CORS must never be treated as auth.
