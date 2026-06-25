# ADR 0022 — Schema-driven connector sync-settings surface + first-class folder discovery (v8.25)

- **Status:** Accepted
- **Date:** 2026-06-25
- **Cycle:** v8.25
- **Builds on:** ADR 0009 (connector boundary / `HostIngestionBridge`); ADR 0017
  (multi-account & project-scoped connectors); ADR 0021 (v8.24 IMAP folder
  selection — the host-side `ImapFolderListingService` + `folders.include` /
  `date_window_days` picker this ADR generalises and whose discovery choice it
  supersedes); the `padosoft/askmydocs-connector-base` (v1.4) capability interfaces
  and the `padosoft/askmydocs-connector-imap` (v1.4) implementation.

## Context

v8.24 (ADR 0021) gave operators a folder picker for IMAP, but it did so with two
shortcuts that do not generalise:

1. **Folder discovery lived in the host**, hard-wired to IMAP
   (`ImapFolderListingService` built a Webklex client directly). That re-derives
   in the host what the connector package already knows how to do, and it never
   worked for `xoauth2` because the host could not reproduce the package's exact
   XOAUTH2 client wiring.
2. **Only two fields were editable** (`folders.include`, `date_window_days`) via a
   bespoke request rule and a bespoke resource key. Every other knob the IMAP
   engine reads — `folders.exclude`, `body_format`, `only_unseen`, sender/recipient
   filters, attachment policy, `reconcile_deletions`, `max_messages_per_sync`, … —
   was reachable only by a hand DB edit. Adding the next field meant editing the
   request, the resource, the FE form and the tests by hand: O(fields) host churn
   for what is connector-owned knowledge.

The real requirement (the dev's folder ask, widened): an operator must be able to
**see and edit the connector's FULL sync-settings surface from the UI** — folders
to include AND exclude as *lists*, the date window, and every other safe knob — and
a new connector must light all of this up **without touching host code**.

## Decision

1. **Two capability interfaces in `connector-base` v1.4; the host branches on
   `instanceof`, never on connector name (R23).**
   - `SupportsConnectionSettings::connectionSettingsSchema(): array` — the
     connector advertises its editable config surface as a list of
     `CredentialField::toArray()` shapes. Each field's `name` is a **dotted
     `config_json` path** (`folders.include`, `limits.max_messages_per_sync`), its
     `type` drives rendering + validation, `target='config'`, never secret.
   - `SupportsFolderDiscovery::listAvailableFolders(int $installationId): array` —
     the connector lists its own live folders/labels using its own client builder
     (fixes XOAUTH2), throwing `ConnectorApiException` (unreachable/transient) or
     `ConnectorAuthException` (rejected/refresh-failed) so the host can map them to
     distinct HTTP statuses.
   - `CredentialField` gains `multiselect` + `tags` types and an optional
     `discovery` hint (e.g. `'folders'`) that tells the UI a multiselect's options
     come from a live discovery call. Additive — the `toArray()` shape grows one
     key (R27).

2. **One host core service, three thin surfaces (R44).**
   `App\Services\Admin\Connectors\ConnectorSettingsService` holds all logic:
   `schemaFor()` (resolve the connector + read its schema, `[]` when it advertises
   none), `currentSettings()` (read each schema field's current value out of
   `config_json`, **never** a connection/secret value), and `mergeIntoConfig()`
   (write ONLY schema-declared fields via `data_set` on a whitelist — a settings
   payload can never inject a non-schema `config_json` key; a present-but-**null**
   value CLEARS the override by `Arr::forget`-ing the key, so the connector default
   applies again). The three surfaces are adapters over it:
   - **HTTP** — `ConnectorInstallationResource` embeds `connection_settings_schema`
     + `settings`; `PATCH /api/admin/connectors/{id}` validates the `settings`
     object **dynamically** from the schema (`UpdateConnectorInstallationRequest`
     derives a rule per field — `multiselect`/`tags` → nullable array of distinct
     non-empty strings, `number` → nullable bounded integer, `checkbox` → boolean,
     `select` → nullable `Rule::in`), rejects an unknown/typo'd/mis-shaped key with
     **422** (never a silent no-op, R14), and persists via `mergeIntoConfig` inside
     `lockForUpdate` (R21). The v8.24 `folders` / `date_window_days` keys stay for
     back-compat (R27), with the legacy bound aligned to the schema-driven rule.
   - **MCP** — `ConnectorSettingsTool` (read-only, idempotent, tenant-scoped R30)
     returns the schema + current values and, opt-in, the live folder list; a
     discovery failure is a distinct `folders_error`, never a misleading empty list
     (R14). Roster 44 → **45** (locked by `KnowledgeBaseServerRegistrationTest`).
   - **CLI** — `connectors:configure {installation} --tenant --set=* --show` shows
     the schema + values and applies `name=value` overrides, casting by field type
     with the SAME constraints the HTTP surface enforces (integer bounds, list
     distinct/length, nullable clear) and failing fast on an unparseable value or a
     `--set` against a connector that exposes no settings (no silent coercion).

3. **Generic, schema-driven host folder discovery.**
   `ConnectorFolderListingService` (renamed from the v8.24 IMAP-specific service)
   resolves any installation through the `ConnectorRegistry` and calls
   `listAvailableFolders()` when the connector `instanceof SupportsFolderDiscovery`
   — a non-discovering connector 404s, a source failure becomes a
   `ConnectorFolderListingException` → **503** (R14). The endpoint and the FE live
   multiselect are connector-agnostic.

4. **A whitelisted folder that has since disappeared upstream NEVER stops the
   sync.** `MailboxWalker::missingIncludedMailboxes()` diffs `folders.include`
   against the live mailbox list; the IMAP engine ingests every folder that *does*
   exist, and records each missing one onto `SyncResult.errors[]` + a
   `Log::warning` (connector-imap v1.4.2). Configure include `{a,b,c}`, delete `b`
   from webmail, and `a`/`c` keep syncing while the run reports `b` is gone — the
   operator sees it in AskMyDocs's sync errors without a hard failure.

## Consequences

- **Adding a knob is now connector-only.** A connector grows its
  `connectionSettingsSchema()` by one entry and the UI form, the PATCH validation,
  the MCP read and the CLI all pick it up for free — zero host churn (contrast the
  v8.24 O(fields) host edits).
- **Folder discovery works for every auth mode**, including `xoauth2`, because the
  connector builds its own client. The host no longer re-derives IMAP internals.
- **Tri-surface from day one (R44).** PHP (command/service), HTTP (resource +
  PATCH), MCP (tool) — all over one core, each tested at its layer; the FE adds the
  schema-driven `ConnectionSettingsForm` (Vitest + Playwright).
- **Cross-surface parity is enforced, not assumed.** The CLI, HTTP and the merge
  core agree on bounds, list constraints and null-clear semantics, so a value
  accepted on one surface is never silently rejected (or silently dropped) on
  another.
- **Security boundary is explicit.** `mergeIntoConfig` writes only schema-declared
  paths, so an attacker-supplied `settings` key outside the schema is rejected at
  validation and ignored by the merge — the surface cannot be used to poke arbitrary
  `config_json`. Connection host/username and the vault secret are never read back
  into any surface.
- **Resilience is the default**, matching how operators actually manage mailboxes:
  folders come and go, and the sync degrades to "skip + report", never "stop".
- **Roster + counts move in lockstep** — MCP 44 → 45, `connector-base` `^1.4`,
  `connector-imap` `^1.4`, registration test asserts 45.

## Alternatives considered

- **Keep the v8.24 host-side IMAP discovery and just add more bespoke fields.**
  Rejected: O(fields) host churn per knob, and it never fixes XOAUTH2 discovery.
- **A connector-name `switch` in the host to know each connector's fields.**
  Rejected (R23): the host must not enumerate connectors; the connector advertises
  its own schema and the host branches on `instanceof` only.
- **Fail the whole sync when an included folder is missing.** Rejected: a single
  deleted label would silently stop ingesting the surviving folders — the opposite
  of what an operator wants. Skip + report is the safe default.
- **Expose raw `config_json` for editing.** Rejected: leaks connection/secret
  shape and lets any key be written; the schema whitelist is the safety boundary.
