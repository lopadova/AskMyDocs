# ADR 0008 — v4.5 Universal Connectors + source-aware ingestion + modern chat surface

**Status**: Accepted
**Date**: 2026-05-12
**Cycle**: v4.5 W1..W8

## Context

The v4.5 cycle pursued three intertwined goals on top of the v4.4.0 GA
host platform:

1. **Universal Connectors** — bridge AskMyDocs to the seven external
   knowledge sources that enterprise consumers most often ingest from
   (Google Drive, Notion, Evernote, Fabric, OneDrive, Confluence, Jira).
   This is the largest competitor-absent gap surfaced by
   `docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md`
   (Section 6.B "Top 5 highest-leverage gaps").
2. **Source-aware ingestion** — every connector has a native chunk
   shape (Notion blocks ≠ Confluence pages ≠ Jira issues ≠ Office docs
   ≠ Evernote atomic notes ≠ PDF pages). Forcing every source through
   the generic `MarkdownChunker` loses citation precision and tanks
   retrieval quality.
3. **Modern Chat Surface (Vercel AI SDK UI)** — close the parity gap
   against Claude Desktop / ChatGPT Plus / Vercel reference apps on
   the seven Tier 1 affordances that users now reflexively expect
   (stop streaming, regenerate, branch from a message, edit user
   prompt, per-message provider+model badge, token+cost meter, copy
   code blocks). Both audits flagged this gap.

The cycle landed five architectural decisions documented below (D1–D5).

## Decision D1 — Seven connectors ship INLINE in v4.5; package extraction in v4.6

Every v4.5 connector lives under `app/Connectors/BuiltIn/<Provider>Connector.php`
plus its support classes under `app/Connectors/BuiltIn/<Provider>/`.
`config/connectors.php::built_in` is the registration manifest.

### Alternatives considered

- **Extract to padosoft/* packages from day 1**: rejected. The
  connector framework (`ConnectorInterface`, `BaseConnector`,
  `OAuthCredentialVault`, `ConnectorRegistry`, `ConnectorSyncJob`)
  itself was being iterated cycle-internally with breaking shape
  changes through W1..W6. Pushing every iteration to seven separate
  package repos would have ballooned the cycle from 7 weeks to ~14
  weeks while delivering zero additional consumer value (consumers
  install AskMyDocs, not the bare connector package).
- **Ship the framework as a package + connectors inline**: rejected.
  Same reason — the framework shape was not stable until W7 when the
  last connector landed.

### Consequences

- Hosts upgrading to v4.5.0 GA pull seven new connectors as part of
  the AskMyDocs codebase; no new composer requires; no new Packagist
  publishes; no new CI pipelines.
- The v4.6 cycle (locked-in 2026-05-12 per the roadmap) extracts the
  seven connectors + a shared base into eight `padosoft/askmydocs-connector-*`
  packages WITHOUT touching `ConnectorRegistry` — the auto-discovery
  hook is already in place (see D2). The v4.6 work is mechanical
  per-package extraction + tagging + Packagist publish; no
  architectural redesign.
- Once v4.6 lands, AskMyDocs's `composer.json` `require` block
  replaces the inline code with package constraints, and
  `config/connectors.php::built_in` shrinks toward `[]` while
  `ConnectorRegistry` resolves the same seven connectors via
  composer-lock auto-discovery.

## Decision D2 — Auto-discovery via `composer.json::extra.askmydocs.connectors` (composer-lock-driven, NOT root `extra`)

`App\Connectors\ConnectorRegistry::bootFromComposerLock()` reads the
list of installed packages from `composer.lock` (NOT
`composer.json::extra` of the host), iterates each package's own
`extra.askmydocs.connectors` array, and registers each FQCN under
the same R23 boot-time validation + `supports()` mutex check used by
the built-in branch.

### Why composer-lock-driven, not root-`extra`-driven

Mirrors Laravel's own `extra.laravel.providers` auto-discovery
pattern. Three concrete benefits:

1. **A package self-declares its connectors** — the host doesn't
   maintain a parallel list. Removing the package removes the
   connector with zero host edits.
2. **Lockfile-driven discovery survives `composer install` on a fresh
   clone** — `composer.lock` is the source of truth for "what is
   actually installed", which is exactly the question the registry
   needs to answer.
3. **Future-proof for community-authored connectors** — a contributor
   running `gh repo create padosoft/askmydocs-connector-trello` only
   needs to add `extra.askmydocs.connectors` to its `composer.json`;
   `composer require` + `php artisan config:cache` + `php artisan
   queue:restart` are the only operator steps.

### Alternatives considered

- **`config/connectors.php::packages` as a host-maintained array**:
  rejected. Forces every consumer to edit a config file after every
  `composer require`. Defeats the point of package distribution.
- **Service-provider auto-discovery via the package's own
  `register()` method**: rejected. Mixes connector registration into
  the framework boot order; harder to introspect ("which connectors
  is this host running?" requires walking the IoC, not just reading
  the lockfile).

### Consequences

- v4.5 ships the hook but exercises only the built-in branch (zero
  packages declare `extra.askmydocs.connectors` yet).
- v4.6 flips the discovery balance: built-in array empties, package
  array fills. The transition is non-breaking because the registry
  resolves the same FQCNs whether they come from the built-in list or
  the lockfile.

## Decision D3 — `ChunkerRegistry` design surface lives inside `PipelineRegistry::resolveChunker()` + R23 invariants apply at boot

The W5.5 design document
(`docs/v4-platform/DESIGN-v4.5-W5.5-source-aware-ingestion.md`)
named the dispatch surface `ChunkerRegistry`. The implementation
reused the existing `App\Services\Kb\Pipeline\PipelineRegistry`
because chunker dispatch already lived next to converter dispatch
and enricher dispatch; splitting it into a sibling registry would
have duplicated the R23 invariants (FQCN-at-boot validation +
`supports()` mutex check) without delivering a different surface
contract.

Concrete impl:

- `PipelineRegistry::__construct()` reads `config/kb-pipeline.php::chunkers`
  (the chunker FQCN list shipped with AskMyDocs core, e.g.
  `NotionBlockChunker`, `ConfluencePageChunker`, `JiraIssueChunker`,
  `OfficeDocChunker`, `AtomicNoteChunker`, `PdfPageChunker`,
  `MarkdownChunker`).
- For each FQCN: must `implements ChunkerInterface` (R23 first invariant).
- For each pair of chunkers: their `supports($sourceType)` predicates
  MUST NOT overlap (R23 second invariant). First-match-wins dispatch
  is undefined behaviour if two chunkers claim the same source type.
- `resolveChunker($sourceType): ChunkerInterface` is the
  consumer-side entry point. `DocumentIngestor::ingest()` calls it
  exactly once per ingestion.

### Why first-match-wins + supports() mutex (R23)

- Determinism — operators can read `config/kb-pipeline.php` and tell
  exactly which chunker handles which source type.
- Cheap boot-time failure — a misconfigured registry crashes
  `php artisan` immediately, not on the first ingestion under
  production load.
- Mutex check catches the silent-pick failure mode (e.g.
  `PdfPageChunker::supports('pdf')` AND `LegacyPdfChunker::supports('pdf')`
  both true → registry rejects the second class with a clear error,
  not picks the first one silently).

### Consequences

- Every new chunker (community-authored or AskMyDocs-internal)
  declares a unique source type. Two-chunker-per-source dispatch
  needs a second sub-key (e.g. `source_type=pdf` + `mime=application/pdf+annotated`)
  and an ADR amendment.
- The W5.5 design doc's `ChunkerRegistry` name remains accurate as
  the LOGICAL surface; the implementation lives inside
  `PipelineRegistry` for code-locality reasons.

## Decision D4 — Vercel AI SDK UI Tier 2 stretch (#8, #9, #11, #12, #13) deferred to v5.0

W7 shipped Tier 1 in full (#1–#7) plus the first Tier 2 affordance
(`SuggestedFollowupGenerator` — pill chips under the last assistant
message). The remaining five Tier 2 items audited in
`docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md` are
deliberately deferred to v5.0:

- **#8 Tool-result rendering in the message stream**
- **#9 Streaming source-document parts (vs current end-of-stream `data-citations`)**
- **#11 Conversation export (JSON / Markdown)**
- **#12 Image attachments on user messages**
- **#13 Artifact panel (canvas)**

### Why defer

Three of the five (#8, #9, #13) all touch the SAME architectural
question: **what is the persistence shape for streaming
message-parts?**

- The current `messages.metadata` JSON column was designed for v4.0
  citations and v4.4 confidence/refusal sidecar data. It was not
  designed for arbitrarily-nested SDK v6 frame payloads
  (`tool-result` with nested JSON args, `source-document` with chunk
  contents, `artifact` with multi-MB canvas state).
- The v5.0 cycle (locked-in 2026-05-11 per the roadmap) ships an
  MCP **client** framework — AskMyDocs becomes an MCP CLIENT that
  invokes external tools through the chat surface. The natural
  storage for "tool was called → tool result came back" is the
  SAME row that future tool-result rendering reads (#8). Designing
  that storage shape once, in v5.0, with the MCP dispatcher needs
  in mind, is cheaper than designing it now in v4.5 and
  re-architecting it in v5.0.
- The artifact panel (#13) and tool-result rendering (#8) both
  display generative output. They should share one
  display-and-storage contract, not diverge across two cycles.

### Why partial Tier 2 in v4.5 (suggested-followups specifically)

`SuggestedFollowupGenerator` does NOT need a new persistence shape —
the follow-up pills are derived on-the-fly from the assistant's last
reply and tossed when the user picks one or sends a new prompt.
Shipping it in v4.5 closes a visible UX gap without committing to
the v5.0 storage redesign.

### Consequences

- v4.5.0 GA chat surface has parity with Claude Desktop / ChatGPT
  Plus on the seven Tier 1 affordances + suggested-followups (Tier 2
  item #10).
- v5.0 ships #8 + #9 + #13 as a coordinated bundle with the MCP
  client framework + tool registry. #11 + #12 are smaller; either
  v5.0 or a v5.0.x patch.
- Anyone who needs (e.g.) conversation export today can use the
  existing chat-log database driver + a custom Artisan command;
  this is not a regression vs v4.4.

## Decision D5 — Live-fixture recording is opt-in nightly via `workflow_dispatch` (no provider cost in CI)

The W5.5 + W6 live-test suite (`tests/Live/Connectors/`) is gated
behind a per-connector env var (`<CONNECTOR_KEY>_LIVE_FIXTURE_RECORD=1`
+ `<CONNECTOR>_OAUTH_TOKEN=...`). Without the env var, every
test inside the tree calls `markTestSkipped` immediately.

### Why opt-in nightly via workflow_dispatch (NOT default CI)

- Provider cost — running the seven-connector live suite invokes
  real OAuth-protected APIs against real provider tenants; even at
  free-tier quotas, running it on every PR push would amplify
  Google / Notion / Evernote / Fabric / Microsoft / Atlassian API
  bills + tickle their rate limits.
- Determinism — provider responses drift run-to-run (Notion
  pagination cursors expire, Jira issue counts change). Live mode
  catches integration drift; replayed fixtures (the default CI
  path) catch regression. Both have a place.
- Credential safety — `workflow_dispatch` is operator-triggered, so
  Lorenzo (or any future ops-on-call) can pause the workflow if
  provider credentials are compromised without touching the default
  CI gate.

### Consequences

- Default CI runs `Unit` + `Feature` only — same cost / latency as
  v4.4. Hosts upgrading to v4.5 see no CI-time inflation.
- Operators with access to real provider tenants run the live suite
  via `gh workflow run live-fixtures.yml` (or its local equivalent)
  to refresh the fixture corpus. The refreshed fixtures get
  committed; the default CI loop then catches regression at zero
  provider cost.
- The runbook (`docs/v4-platform/RUNBOOK-live-fixture-recording.md`)
  is the single source of truth for credential setup + execution
  steps + replay verification.

## Cost & disk impact

- **Provider spend (default-OFF)**: zero new provider cost on a v4.5
  upgrade. Hosts that install the first connector pay the marginal
  cost of that connector's API + ingestion-time embedding spend.
- **Disk impact**: each connector adds ~6–10 new tables/columns +
  per-installation metadata under `storage/app/connectors/`. Far
  below the day-one ingestion footprint.
- **Test fixtures**: live-recorded fixtures land under
  `tests/Live/Connectors/<provider>/fixtures/` with redaction
  filters applied (PII-scrubbed). Each provider adds ~150–500 KB
  of replay payloads; trivial.

## Consequences (cycle-wide)

- AskMyDocs is now competitor-credible for the "self-hostable RAG
  over external KB" use case — operators can plug GDocs + Notion +
  Confluence + Jira + Office docs + Evernote + Fabric without
  brittle per-team scripts.
- Source-aware ingestion makes the citation panel meaningfully more
  precise on non-markdown corpora (Confluence pages cite specific
  storage-format blocks; Jira issues cite issue body + matching
  comment; Notion pages cite specific blocks).
- The modern chat surface puts AskMyDocs UX-parity with the SaaS
  reference apps. Tier 2 stretch + agentic tool use ship in v5.0.

## Alternatives considered (rollup)

- **Build only 3 connectors (Drive + Notion + Confluence) in v4.5,
  defer the rest**: rejected. Patent-Box auditor signal +
  competitor audit signal both indicated all seven were table
  stakes for the "Universal Connector" narrative. Three connectors
  would have left the cycle indistinguishable from a typical RAG OSS.
- **Skip W5.5 source-aware ingestion entirely; ship every connector
  through MarkdownChunker**: rejected. Forcing Jira issues +
  Confluence pages + Office docs through a heading-based chunker
  produces visibly worse citations and tanks retrieval score on the
  golden corpus.
- **Ship Vercel SDK UI Tier 2 in full as v4.5/W7**: rejected per D4.
- **Run live-fixture suite on every PR**: rejected per D5.
