# Email triage before ingestion

Email triage is a host module for IMAP installations. It is opt-in and initially
disabled for every account. Both incremental sync and backfill register the same
accepted-attachment manifest before calling the host ingestion bridge. A durable
body receipt gates only the email ingest; attachment ingest continues independently.
No decision call runs while the download holds its IMAP session.

## Configuration and decisions

Open **Connectors → Edit connection → Triage email**. Configure up to ten active
characteristics: independent yes/no tags, exclusive categories or ordered levels.
Each option has a criterion, deterministic footer text and an optional search tag.
Each save creates an immutable profile revision; existing installations retain
their unrelated connector settings and credentials.

### Configuration assistant

The panel starts with **Configura con un prompt**. Describe the characteristics
to recognize, or choose an example for customer support, orders/shipments or
commercial requests. **Prepara le regole** makes one generative OpenRouter call
and returns a proposed set of rules plus fictional email examples. The assistant
receives only the masked prompt and editable rule definitions; it never reads
mailbox messages, installation credentials or documents.

Review the proposed labels, tags and examples, then choose **Usa questa bozza**.
This replaces the editable rule list while preserving the account activation
choice. **Salva regole** is a separate action. Generating or applying a draft
does not save a profile, enable triage, launch a campaign or ingest an email.
Manual changes discard an unapplied proposal so stale suggestions cannot
overwrite later edits.

The panel shows compact rule summaries. Open **Modifica caratteristica** for
labels, criteria, card text and tags; stable keys and confidence thresholds live
under **Parametri avanzati**. Email previews, historical campaigns, profile
history and service details are collapsible sections.

The helper uses the existing metered `AiManager` integration with OpenRouter;
`EMAIL_TRIAGE_ASSISTANT_MODEL` overrides its model (otherwise the configured
`CHAT_PREPROCESSOR_MODEL`, default `openai/gpt-4o-mini`). It requests strict JSON
Schema, validates the response again with the real profile rules and rejects
malformed, truncated, over-limit or inconsistent proposals. At most six requests
per user and tenant per minute can reach the model. Prompt text, rule text and
generated examples are absent from the administrative audit; model, counts,
token usage and duration are recorded. Email classification still uses Jev.

One combined Jev request asks every active question. The initial configurable
threshold is 0.85; calibrate it on labeled examples before broad activation.
Uncertain results are recorded as “Non determinabile” and generate no positive
search tag. Provider errors do not count as negative classifications. The
dedicated [OpenRouter Decisions API](https://openrouter.ai/docs/guides/community/jev)
returns structured judgments; the application renders the footer itself.

The existing Decisions sanitizer masks recognizable email addresses, phones,
payment identifiers and secrets before external transmission. Other private
business content is still sent to OpenRouter: account administrators must take
that into account before enabling the module or running a paid preview.

The body has priority within `decisions.max_state_bytes` (32 KiB by default,
measured after sanitizing UTF-8 JSON). Oversized bodies retain beginning and end
with visible partial coverage. Imported attachments contribute at most 1,500
initial characters each and 8 KiB together. Extraction accepts text/HTML, the
initial XML text of DOCX/ODT and the first PDF page through local `pdftotext`.
It never invokes paid OCR. Binary files above 5 MiB are omitted from this preview;
the normal attachment ingest policy still determines their ingestion.
Missing, excluded, encrypted or unsupported files are recorded in coverage.

Original email bytes and small attachment snapshots are immutable, hash checked,
and stored below `.artifacts/email-triage/`. Folder walkers exclude this generated
namespace; the conversion-artifact sweep only enumerates `.md`, not these `.source`
files. These snapshots deliberately remain available for retry and retrospective
analysis. Include their disk usage in normal tenant storage management.

The parse step reads the captured original and appends one generated card to the
Markdown passed to chunking/embedding. It keeps the logical source path, origin
metadata and access scope. Automatic search tags also reach chunk metadata through
the document's `_derived.search_tags` and every chunk's `search_tags` field. A newer card uses normal document versioning and a stable
revision-specific Flow run key; retries reuse the saved decision. Historical
reindexing copies manual/source ACL rows and existing document tags before archiving
the previous version. The card explicitly identifies its automated provenance.
Connector provenance must survive parsing and persistence: an imported IMAP
parent remains `connector=imap`, so historical campaign filters can find it.
Chat evidence for a triaged parent rereads the hash-verified original without
the generated card. Search tags navigate to a source; they do not become the
sender's statements or independently prove a sentiment classification.
For a long original, the reader keeps the email/table header with a bounded
matching window and marks omitted text explicitly. If the generated card's
Markdown separator was assigned to the last original chunk, it removes that
separator only for navigation and still requires an exact match in the verified
raw body. A generated-card-only chunk cannot become original evidence.

## Queues and deployment

Apply the new migration and build the frontend:

```sh
php artisan migrate
npm run build
```

Run two workers on the dedicated Redis connection `email-triage`. Its 240-second
lease exceeds the 150-second job timeout. Do not put this queue on the download
worker. The local Herd/launchd templates can be installed independently:

```sh
zsh ops/launchd/install-local.sh --triage-only
```

For other hosts, configure two supervised instances of:

```sh
php artisan queue:work email-triage --queue=email-triage --sleep=1 --timeout=150 --tries=4
```

The existing Laravel scheduler invokes `email-triage:pump` every minute. It
recovers committed work whose dispatch was interrupted and advances campaigns
in independent batches of 50. It also dispatches a heartbeat; enabling a profile
requires server-side OpenRouter credentials, an asynchronous queue and a recent
worker heartbeat. Activation also verifies credentials on the Decisions endpoint
using a tiny synthetic request, with a 15-minute credential/model-scoped cache;
the probe does not contain mailbox content. Account activation is a separate explicit panel action.
Decision retries wait 30, 120 and 600 seconds. After exhaustion the email remains
downloaded and visibly failed; retry controls can resume it. An ingest failure
reuses the saved card and incurs no new decision call. A manual retry increments
a durable index generation so previously failed Flow runs cannot block it;
duplicates within the same generation retain a deterministic run key.
Disabling an account's triage stops queued decisions and pauses its historical
campaigns. Downloads continue through the existing import path. Previously queued
triage work can proceed once the account's triage is enabled again; campaigns
require explicit resume.

## Retrospective campaigns and observability

Filter active email parent documents by account, project, folder, date and state
(never triaged, obsolete rules, failed or all). Preview reports the exact selected
count and source availability checked on at most 100 emails; the unchecked count
is explicit. Decision cost estimates use recent observed usage or
`EMAIL_TRIAGE_ESTIMATED_DECISION_USD`; embedding/reindex estimates use
`EMAIL_TRIAGE_ESTIMATED_REINDEX_USD`. Without a rate or observation, estimates are
reported as unavailable rather than invented. Actual decision usage is recorded
per evaluation and in structured logs.

Starting a campaign pins the saved profile revision and an upper document-ID
boundary. A concurrent new email or a newly indexed revision cannot extend the
campaign indefinitely. Pause stops new campaign decisions at job boundaries;
in-flight work may finish. Resume keeps its cursor and captured sources.
The panel lists failed/unavailable items and allows individual retries.

Recovery reads a captured original, a still available source, or a complete
conversion artifact verified against its content hash. It never reconstructs a
body from retrieval chunks and never redownloads IMAP automatically. An unavailable
body is reported per item. Rule changes mark older profiles obsolete but never
launch a paid archive-wide rerun automatically.

The authenticated `manageConnectors` API lives below
`/api/admin/connectors/{installationId}/email-triage`:

- `GET` status, profile history, recent email execution/coverage/index state.
- `PUT` a validated profile revision.
- `POST /suggest` with `prompt` and optional editable `profile`: generate a
  validated configuration proposal without saving or enabling the account.
- `POST /preview` with `document_id` and optional draft `profile`: paid analysis,
  no source staging or ingestion.
- `POST /campaign-preview` and `/campaigns` with filters and the previewed
  `profile_id`; a changed profile requires a new preview.
- `POST /campaigns/{id}` with `action: pause|resume`.
- `POST /messages/{id}/retry` or `/campaigns/{id}/items/{itemId}/retry`.

Execution, coverage, profile validity and indexed revision are separate states.
Original identities include tenant, installation, mailbox, UIDVALIDITY and UID.
Evaluation identities include the original/manifest hash, profile/model and
renderer version. Logs contain IDs, stages, attempts, latency, usage and error
codes; neither raw state nor email/attachment content is logged.

## Read-only real mailbox pilot

After authorizing transmission of the selected private content to OpenRouter:

```sh
php artisan email-triage:pilot rotta-logistics 10 --rounds=10
```

This performs real read-only IMAP downloads and decision previews with the saved
rules, or limited starter rules if none exist. Every round rereads the message to
compare its body and flags. It records download/decision time, usage, memory and
coverage in a content-free local report. It does not enable accounts, stage KB
sources, create evaluations or dispatch ingestion. It is a throughput/integrity
check, not a substitute for a human-labeled accuracy evaluation.

### Autry pilot, 11 October 2026

Ten distinct real messages completed ten decision rounds with the starter profile,
without activating the account. Body hashes and IMAP flags matched before and
after every round. Each rendered card appeared once. No triage evaluations or KB
documents were created, and no ingestion was dispatched.

| Measurement | Observed value |
| --- | ---: |
| Download time, mean per message | 342 ms |
| Jev decision time, mean / maximum | 451 / 678 ms |
| Reported total decision cost | USD 0.000962304 |
| Reported input tokens | 22,912 |
| PHP peak allocated memory | 58.5 MiB |
| Partial coverage | 2 / 10 messages |

The local content-free report is
`storage/app/email-triage/pilot-20261011-175902.json` (intentionally not committed).
Two dedicated local workers were installed and their heartbeats verified; existing
accounts remain disabled. This small recent-message sample verifies operation and
integrity, not full-mailbox throughput or classification accuracy. Retrieval tags
and replacement/versioning are separately covered by the automated ingest tests;
human labels are still needed to calibrate each tenant's classification thresholds.
