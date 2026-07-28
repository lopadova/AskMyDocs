# ADR 0023 — Audited widget identity credentials and explicit current-session restore (v8.30)

- **Status:** Accepted
- **Date:** 2026-07-28
- **Cycle:** v8.30
- **Builds on:** the v8.10 KITT widget boundary, R21 atomic credential
  consumption, R30 tenant scoping, R44 multi-surface capabilities.

## Context

Authenticated host users add two security-sensitive lifecycles to KITT:

1. the host backend owns a long-lived, server-only `ik_` credential used to
   mint short-lived browser `wu_` tokens;
2. the widget restores the newest session that can still accept a turn.

The first implementation mutated the identity hash directly in the HTTP
controller and had no durable audit or deterministic stale-writer rejection.
The browser restored history by listing page one and searching those 20 rows for
an open session. More than 20 newer closed sessions could therefore hide an
older active session.

R44 normally asks for PHP, HTTP and MCP parity. Returning a one-time `ik_`
plaintext through MCP would, however, place a server credential in an agent
transcript or other persistent agent context.

## Decision

1. **One credential lifecycle service.**
   `WidgetIdentityCredentialService` is the only code allowed to enable or
   disable authenticated users, create the first `ik_`, or rotate its hash.
   HTTP and `widget:identity-credential` are adapters over the service.

2. **Tenant scope, row lock, version and audit are one transaction.**
   Mutations select the key with tenant scope and `lockForUpdate`, compare the
   caller's `identity_credential_version`, update the hash and monotonic version,
   then append allowlisted `admin_command_audit` rows. An audit write failure
   rolls back the credential mutation. A stale expected version returns the
   typed `identity_credential_conflict` error. Audit payloads never contain
   plaintext or hashes.

3. **Deliberate R44 exception for MCP mutation.**
   PHP and HTTP may return the one-time secret to the operator. There is no MCP
   enable/rotate mutation because the value must not transit an agent
   transcript. An agent-facing surface may expose read-only enabled/version
   metadata, but not a mutation and never a secret.

4. **Explicit current-session query.**
   `GET /api/widget/sessions/current` requires a `wu_`, scopes by tenant, widget
   key, project and pseudonymous identity, filters `active`, `waiting_user` and
   `waiting_tool`, then orders by `updated_at DESC, id DESC`. It returns one
   session or `204`. Paginated `/sessions` remains a history browser and is no
   longer part of boot restoration.

5. **Token invalidation semantics.**
   Disabling user auth advances `identity_access_epoch` and immediately rejects
   existing `wu_` and identity-bound `wt_` tokens. Validators read both the live
   switch and epoch on every request, so re-enabling cannot resurrect a bearer
   minted before disable.
   Rotating `ik_` immediately prevents minting with the old secret, while
   already-issued `wu_` tokens remain valid until their configured TTL.

6. **Fullscreen is an embed mode, not an in-widget toggle.**
   `mode: "fullscreen"` is always open, fills the viewport and exposes the chat
   as an accessible `region`. There is no enter/exit button and therefore no
   transient focus switch: the host chooses helper, inline or fullscreen when
   embedding.

## Consequences

- Concurrent or retried admin requests cannot silently overwrite a newer
  credential.
- Operators have an append-only forensic trail without leaking secrets.
- Session restoration is independent of history length and cannot cross an
  identity, project, key or tenant boundary.
- Rotation has low user disruption; disable remains the immediate incident
  response.
- MCP has a documented, security-motivated parity exception rather than a
  misleading partial mutation.

## Alternatives considered

- **Paginate until an open session is found.** Rejected: slower boot, more
  requests and a race-prone client-side search for a server-owned concept.
- **Invalidate every `wu_` on rotation.** Rejected: rotation is routine
  maintenance; disable already provides immediate revocation when required.
- **Return `ik_` from an MCP tool.** Rejected: durable agent contexts are the
  wrong place for a one-time server credential.
- **Add a fullscreen toggle inside KITT.** Rejected: the product contract is a
  dedicated fullscreen embed, while helper already provides open/close UX.
