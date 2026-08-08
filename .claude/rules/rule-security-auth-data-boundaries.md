# Rule: authentication, authorization and data boundaries

## Identifiers

`SEC-AJAX-001`, `SEC-MFA-API-001`, `SEC-TOKEN-001`, `SEC-AUTHHARD-001`,
`SEC-IDOR-001`, `SEC-BOADMIN-001`, `SEC-OFFBOARD-001`, `SEC-PWPOLICY-001`,
`SEC-PWNED-001`, `SEC-SIGNURL-001`, `SEC-BOUNDARY-001`.

## Mandatory controls

- Every HTTP, CLI, queue, MCP and widget entrypoint has an explicit authentication
  and authorization decision. Route middleware alone is not enough when another
  entrypoint reaches the same service.
- Tenant-aware reads, writes, aggregates, exports, relationships and eager loads
  scope by trusted `TenantContext`; request/model-selected IDs never establish
  ownership. Apply R30-R32 and test cross-tenant denial.
- A feature flag, environment value, tenant/admin setting, header, cookie, client
  identifier or prompt instruction is not a credential and cannot disable a
  security gate.
- High-risk admin/API operations require current strong authentication and
  authorization; MFA-sensitive flows cannot be bypassed through tokens, MCP,
  queues or alternate endpoints.
- Admin-panel entry permission is not authorization for every action. Classify
  routes by specific capability and ratchet down generic/missing authorization;
  an empty route inventory is a failed audit. Staff login never accepts or issues
  remember-me credentials, including crafted requests and legacy recaller cookies.
- Token issuance, rotation and revocation use purpose-specific classes, bounded
  lifetime, hashed storage and re-authentication where required. Password/email,
  role, tenant membership and offboarding changes revoke all applicable sessions
  and tokens.
- Password policy is consistent across UI/API/admin/reset paths and compromised
  password checks fail according to an explicit availability policy.
- Signed links use keyed MAC/signature APIs, expiry, purpose/audience binding and
  constant-time comparison. Single-use state is consumed atomically per R21.
- Public/widget/API flows have CSRF/origin/token controls appropriate to their
  authentication model and dedicated identity-aware throttles.
- Session/device binding is a detection/hardening signal, not a credential. Use a
  normalized user-agent fingerprint with constant-time compare and a measured
  report-only rollout; do not bind to unstable/spoofable client IP. Frontend tenant
  state is presentation only and never establishes backend scope.

## Review blockers

Bare tenant-model lookup by attacker-controlled ID, permission checks after data
access, auth governed only by settings, incomplete token revocation, reusable
single-use links, or a protected capability reachable through an unguarded
alternate surface.
