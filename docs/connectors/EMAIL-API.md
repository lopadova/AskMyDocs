# Email API connector host integration

The standalone `padosoft/askmydocs-connector-gmail` (`gmail`) and
`padosoft/askmydocs-connector-microsoft-mail` (`microsoft-mail`) packages extend
`Padosoft\AskMyDocsConnectorBase\BaseConnector`. They require base ^1.6, implement
the native connection settings and folder discovery contracts, and declare
Composer/Laravel discovery. They have no runtime dependency on the host or IMAP.

The normative source is the frozen
[metadata and prepared-event v1 specification](email-api/2026-10-14/STANDARD.md)
with provider details alongside it
in `GMAIL.md` and `MICROSOFT-MAIL.md`. This page explains the host adapter.

## Source identities and revisions

For an API message the parent key is `connectorKey:sha256(JSON identity)`, where
identity is `[tenantId, installationId, connectorKey, mailboxId, nativeMessageId]`.
The JSON flags are `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES |
JSON_UNESCAPED_UNICODE`; all identity entries are strings except the integer
installation ID. Native IDs are case-sensitive. A folder, label, sender or
subject is never part of the key. Body and all attachments share that key.

Mandatory common metadata includes `connector`, `installation_id`,
`email_schema_version: 1`, `email_provider`, `email_mailbox_id`, `email_message_id`,
`email_message_key`, `email_folder_ids`, `email_folder_names`, `email_date`,
`email_attachment_count`, `email_body_hash` and `email_revision_hash`. Attachments
add `email_attachment_id`, `email_attachment_slot`, `attachment_filename` and
`attachment_of_message_id`. Source-aware hints preserve provider data under
`converter_hints` and search signals under `_derived`.

`email_body_hash` attests the Markdown bytes after the required host redaction
and before the generated triage card. The revision SHA256 hashes JSON of the
body hash and ordered semantic manifest tuples:
`[slot, id, name, mime, hash, excluded ? reason : null]`. Mutable receipt states
and snapshot paths do not affect this hash. IMAP retains its original
`imap_doc_key` and identity algorithm; no migration of legacy identities occurs.

## Synchronous preparation

The package fetches and filters one message, applies the attachment policy,
renders/redacts the final body, sorts attachments by case-sensitive provider ID
and assigns stable slots. It dispatches the following event through Laravel
with **one** envelope argument:

```php
Event::dispatch('askmydocs.email.prepared.v1', [[
    'schema_version' => 1,
    'tenant_id' => $tenantId,
    'installation_id' => $installationId,
    'project_key' => $projectKey,
    'metadata' => $bodyMetadata,
    'email_date' => $emailDate,
    'manifest' => $manifest,
]]);
```

Each manifest entry has `slot`, `id`, `name`, `mime`, `hash`, `status` and `reason`.
Accepted files have a hash of the persisted bytes and `pending`; policy-excluded
files have `hash: null`, `status: excluded` and an explicit reason. The host
listener validates tenant, installation, provider, project and the v1 hashes.
With triage disabled, valid preparation has no staging effect. With triage
enabled, it persists the manifest synchronously before returning. Listener
errors propagate to the connector and prevent checkpoint commitment.

The existing ingestion bridge then receives the body and each accepted attachment.
It associates receipts with parent key, revision, slot, ID and hash, captures
immutable snapshots and releases the body only when the manifest is terminal.
An explicit extraction/OCR refusal contributes coverage instead of a false success;
a real write/dispatch error remains retryable. Replaying the same revision keeps
completed receipts and saved evaluations. Jobs for obsolete revisions cannot
project a card onto a new original. The Flow persistence boundary preserves the
API provider key rather than replacing it with `local`, so final revision,
provenance and ACL checks receive the original email v1 identity. Attachments
inherit the current parent scope and grants; prior attachment denials and manual
tags remain, without adding grants that the parent does not allow.

## One host email module

`EmailConnectorRegistry` projects common API metadata with IMAP fallbacks.
`TriageProfiles` advertises the shared capability; `EmailTriageService` handles
preregistration, receipts, readiness and evaluation. `TriageCampaigns` selects
active parent documents and recovers complete originals from snapshots, local
sources or verified conversion artifacts. It never refetches remote mail or
reconstructs originals from retrieval chunks.

The existing authenticated `manageConnectors` HTTP routes and common SPA panel
remain the operator entry points. `ConnectorSettingsTool` reads the same schema
and live discovery used by the UI, and `connectors:configure` operates over the
same host settings service. The triage service and `email-triage:pump` remain the
shared PHP/queue core. Triage has no new autonomous MCP write surface: paid
activation, preview and campaigns retain their existing explicit admin flow.

Campaign filter `mailbox` retains its HTTP shape and denotes an IMAP folder path
or API folder/label ID, matching API array membership. Dates use API
`email_date` and legacy `imap.date`. Parent and attachment recovery is scoped to
tenant and installation; API attachments also match the parent revision.
Profile revision and cutoff ID remain pinned across pause/resume. Availability
preview checks at most 100 originals and discloses unchecked counts; costs come
from observed usage/configured rates or are unavailable.

## Diagnostic and integration limits

Health and `GET /api/admin/connectors/{installationId}/folders` use each package's
own OAuth/API implementation. No API account is sent through an IMAP client.
The sample-download `test-fetch` endpoint and `email-triage:pilot` currently support
IMAP only; API sampling is unavailable until a neutral capability is introduced.
Paid analysis of an already imported original remains an explicit triage preview.

Automated tests use synthetic Gmail/Graph metadata, fake HTTP with
`preventStrayRequests`, fake Storage and queues. These verify shared host behavior
independently of a real OAuth grant. Package discovery/ingestion smoke tests must
be rerun with both Composer packages installed before claiming that deployment
integration. Live OAuth/consent, Google restricted-scope review and Microsoft
organization/shared-mailbox policy are separate deployment verification.

## Local package integration without production path configuration

Use a dedicated worktree and keep its dependency directory isolated. Prepare a
machine-local alternate manifest:

```sh
php tests/Support/prepare-email-api-composer.php \
  --gmail=/absolute/path/askmydocs-connector-gmail \
  --microsoft=/absolute/path/askmydocs-connector-microsoft-mail
COMPOSER=composer.email-local.json composer update \
  padosoft/askmydocs-connector-gmail padosoft/askmydocs-connector-microsoft-mail \
  --with-dependencies --no-scripts --prefer-dist --no-interaction
php artisan package:discover --ansi
vendor/bin/phpunit tests/Feature/Connectors/EmailApiPackageIntegrationTest.php
```

Run package discovery explicitly after `--no-scripts` so an existing Laravel
manifest cache includes both providers, their configuration and migrations.
After changing OAuth environment variables with cached configuration, run
`php artisan config:clear` in this local worktree before testing the connection.

The alternate manifest/lock are ignored and never replace the production
`composer.json`/`composer.lock`. Composer mirrors the packages into the isolated
vendor directory, so repeat the targeted update after package changes. The
`0.1.0` version is a temporary local test alias only. `EmailApiPackageDiscovery`
supplements base lockfile discovery from actually installed Composer metadata,
while preserving the registry's contract checks and other installed connectors.
Neither provider appears when its package is absent. Laravel's normal package
discovery registers each provider in the application; the Testbench smoke
explicitly registers those providers because Testbench bypasses that cache.

The smoke runs native discovery, health and full acquisition through the real
host bridge for Gmail and Microsoft Mail, with triage enabled and disabled.
Queues/HTTP/storage are faked; no mailbox or paid AI is used. Fixture-only host
tests remain mandatory when either unpublished package is unavailable.

See [email API verification](EMAIL-API-VERIFICATION.md) for reproducible fixture and local package checks.
