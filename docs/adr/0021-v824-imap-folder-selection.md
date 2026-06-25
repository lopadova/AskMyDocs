# ADR 0021 — IMAP folder selection + dev/test email-ingest harness (v8.24)

- **Status:** Accepted
- **Superseded by:** ADR 0022 (v8.25) — for the **folder-discovery mechanism only**:
  v8.25 moves discovery into the connector via `SupportsFolderDiscovery`, so the
  host-side `ImapFolderListingService` described below is replaced by the generic
  `ConnectorFolderListingService` (which also fixes XOAUTH2). The rest of this
  record (the `folders.include` / `date_window_days` write contract, the 503/404
  semantics, the email-ingest harness, the `manageConnectors` gate widening) still
  stands and is generalised — not reversed — by ADR 0022.
- **Date:** 2026-06-25
- **Cycle:** v8.24
- **Builds on:** ADR 0009 (connector boundary / `HostIngestionBridge`);
  ADR 0017 (multi-account & project-scoped connectors — `ConnectorInstallation`,
  `ConnectorInstallationService`, `ConnectorAdminController`); the
  `padosoft/askmydocs-connector-imap` package (v1.4) and its
  `ImapClientFactoryInterface` + `OAuthCredentialVault` public seams.

## Context

An IMAP account often holds far more than the KB should ingest — `[Gmail]/Spam`,
`[Gmail]/Trash`, personal folders, years of newsletters. Before v8.24 the sync
walked whatever the connector defaulted to, and the only way to narrow it
(`config_json.folders.include`) was a hand DB edit or a CLI re-merge from the test
harness. That is neither operable nor safe: an operator could not see the
mailbox's real folder names, let alone pick from them, and a typo silently
ingested nothing.

Two constraints shaped the design:

1. **The live folder list only exists *after* the account is ACTIVE** — you need
   working credentials to ask the server what folders exist. So the picker is
   necessarily a **post-install** action, not part of the activation form.
2. **The connector's own client builder is `protected`.** Listing folders from
   the host would normally mean bumping the package to expose a new public method
   — extra release coordination for a host-only UI concern.

## Decision

1. **Host-side live folder discovery, over the connector's PUBLIC seams.**
   `App\Services\Admin\Connectors\ImapFolderListingService` reuses the already-bound
   `ImapClientFactoryInterface`, the `OAuthCredentialVault` secret, and the stored
   `config_json.connection` to open a client and call `listMailboxes()`. No package
   change beyond the routine `^1.4` bump. The paths returned are **verbatim**
   (case-sensitive) — exactly what `folders.include` whitelists — so a picked value
   round-trips 1:1 and never needs normalisation that could drift from the server's
   own naming.

2. **Read endpoint is tenant-scoped and fails loudly.**
   `GET /api/admin/connectors/{installationId}/folders` resolves the installation
   under the active tenant (R30 → 404 on a cross-tenant or non-IMAP id) and maps an
   unreachable server / rejected credentials to a dedicated
   `ImapFolderListingException` → **HTTP 503** (R14). An empty-but-successful list
   stays a valid `200 []` (a mailbox can legitimately have no sub-folders); only a
   real transport/auth failure is a 503 — the caller can always tell success from
   failure.

3. **`folders.include` + `date_window_days` are additive write fields.**
   `UpdateConnectorInstallationRequest` accepts `folders.include` (≤ 200 EXACT
   paths, normalised pre-validation: each trimmed, blanks dropped, deduped, and
   `distinct`-validated; an **empty array is meaningful — "clear the whitelist →
   sync all non-excluded folders"**) and `date_window_days`. `ConnectorInstallationResource`
   pre-fills both so the edit form round-trips (R27 — new keys, nothing renamed).
   The persist is a read-modify-write on `config_json` performed **inside**
   `lockForUpdate` (R21) so two concurrent edits cannot clobber each other.

4. **A dev/test email-ingest harness** lands alongside the feature so the whole
   ingest → chat → tenant-isolation flow is QA-able end to end without a real
   mailbox: seedable IMAP mailboxes (`MailSeedImapCommand`, `ImapMailboxSeeder`,
   `EmailMessageBuilder`, `WebklexMailboxAppender`, and a `FakeImapClientFactory`
   test seam that exercises the flow without touching real IMAP), multi-company
   case-study fixtures, and console drivers (`ConnectorImapInstallCommand`,
   `DemoListCompaniesCommand`, `InitCaseStudiesCommand`). The harness is
   test/dev-only and never alters production behaviour.

## Consequences

- **Folder selection is operable from the UI** — pick from the mailbox's real
  folders, no DB surgery, no guessing names.
- **No package coupling for a UI concern.** Folder discovery stays in the host; the
  connector package keeps its builder `protected`. If a future connector needs the
  same picker, it implements `ImapClientFactoryInterface` (or the host adds a small
  per-connector discovery service) — the HTTP/FE contract is reusable.
- **Surface coverage.** The write rides the existing `ConnectorAdminController`
  PATCH (R32-matrix-locked, R30-scoped) and the config is already MCP-readable via
  `ConnectorInstallationsTool` (ADR 0017); the new live-discovery read is an
  admin-UI affordance, so no new MCP tool was added (roster stays 44). The CLI
  harness covers the PHP surface for repeatable seeding/install.
- **The `manageConnectors` gate widens from super-admin-only to admin +
  super-admin** so an admin (not just a super-admin) can run the folder picker.
  This is a deliberate authorization change applied consistently across the gate
  definition, the route group, the FE `<RequireRole>` guards, the R32
  authorization matrix and `role-access.spec.ts`. The gate still touches
  credential vaults, so it remains an admin-tier capability — never editor/viewer.
- **The 503 distinction matters operationally** — an operator opening the picker
  against a mailbox whose credentials have since expired sees an explicit error,
  not an empty list they'd misread as "no folders".

## Alternatives considered

- **Bump the package to expose a public folder-list method.** Rejected: extra
  release coordination for a host-only UI need; the public seams already suffice.
- **Cache the folder list at activation.** Rejected: folders change over the
  account's life (new labels, archived folders); a stale cached list is worse than
  a fresh live call that fails loudly when the server is unreachable.
- **Normalise/lower-case folder paths.** Rejected: IMAP folder names are
  case-sensitive and server-defined; any normalisation risks a whitelist entry that
  no longer matches the real mailbox.
