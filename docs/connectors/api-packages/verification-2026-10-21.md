# Implementation and verification — 2026-10-21

Implemented on the current AskMyDocsDev checkout. Functional commits:
`44dec0567` (backend, contract and handoff), `b47c3e038` (UI and transport tests).
No deployment, push, GitHub publication or edits to another checkout/repository.
Concurrent chat/session changes were left untouched and excluded from commits.

## Files changed

Backend:

- `app/ApiConnectors/ConnectorConfigTransferService.php`: reuse the existing
  transaction and model/service pipeline; tighten v1 validation, secret handling,
  tenant-scoped exports and reference checks; derive parameter mappings.
- `app/ApiConnectors/ConnectorPackageService.php`: bounded JSON/ZIP reader and
  ZIP writer; no extraction or execution; size, type, path, encryption,
  compression ratio, duplicate entry and CRC checks.
- `app/Http/Controllers/Api/Admin/ApiConnectorConfigController.php`: reuse
  existing authorized routes for multipart files and ZIP downloads; safe
  persistence error messages and no-store export/preview responses.

UI:

- `frontend/src/features/admin/api-connectors/ApiConnectorsView.tsx`
- `frontend/src/features/admin/api-connectors/ConnectorExportButton.tsx`
- `frontend/src/features/admin/api-connectors/ConnectorImportModal.tsx`
- `frontend/src/features/admin/api-connectors/api-connectors.api.ts`
- `frontend/src/features/admin/connectors/ApiConnectionsSection.tsx`

Tests:

- `tests/Feature/Connectors/ApiConnectorPackageTest.php` (new)
- `tests/Feature/Connectors/ApiConnectorUserAgentTest.php` (private shared-header
  omission/re-entry, preserving User-Agent)
- `frontend/src/features/admin/api-connectors/ConnectorImportModal.test.tsx`
- `frontend/src/features/admin/api-connectors/ConnectorExportButton.test.tsx` (new)
- `frontend/src/features/admin/api-connectors/api-connectors-transfer.test.ts` (new)

Documentation:

- `docs/connectors/README.md`
- `docs/connectors/api-packages/README.md`
- `docs/connectors/api-packages/v1/connector.schema.json`
- `docs/connectors/api-packages/v1/connector.example.json`
- `docs/handoff/api-connector-packages.md`
- `docs-site/connectors-api.mdx`
- This verification record.

## Actual results

PHP CLI: 8.4.25; PHPUnit 12.5.37, SQLite in-memory through the existing Testbench
configuration. No production/development database migration or reset was run.

| Check | Result |
| --- | --- |
| Final backend run: `ApiConnectorPackageTest`, `ApiConnectorConfigTransferTest`, `ApiConnectorUserAgentTest` | **22 passed, 312 assertions** |
| Broader backend run, also including `ApiConnectorSharedHeadersTest`, `ApiToolCollectorTest`, `ApiToolExecutorTest` | **38 passed, 378 assertions**; before the final safe error-response/validation additions, which the final run above covers |
| Transfer UI/transport tests plus `ApiConnectionTile.test.tsx`, serial execution | **17 passed** |
| Entire API connector UI directory plus tile, serial execution, default timeout | **88 passed, 2 failed** out of 90 |
| Comparison: RouteConfigModal + ConnectorForm with the original HEAD API client loaded through a temporary test-only loader | **27 passed**; does not establish that the broader suite is stable |
| Further broad UI attempt with a 15-second timeout | Interrupted after another timeout in ConnectorImportModal; no successful broad-suite result claimed |
| `npm run typecheck` | Passed |
| `npm run build` | Passed; Vite reported the existing large-chunk warning |
| Draft 2020-12 schema compilation and example validation with the already installed Ajv | Passed; no dependency added |
| PHP syntax checks and `git diff --check` | Passed |

The two completed broad UI failures were in `RouteConfigModal.test.tsx`:
the outside-click/Escape/close test exceeded 5 seconds; the parameter-source
test did not find `api-route-form-param-0-value`. These tests/components were
not modified for this task. The comparison and timing-dependent results do not
justify claiming all UI tests are green; the broader suite remains unstable.

The new package tests cover the documented example, JSON/ZIP round-trip,
runtime path/query/auth resolution against HTTP fakes, no import-time HTTP,
secret omission, version/reference/shape errors, malformed and unsafe ZIPs,
tenant and role boundaries, and rollback after an endpoint persistence failure.
The existing transfer suite also verifies cancelled saves and missing secrets.

The actual Herd site is `https://askmydocsdev.test`; APP_URL already matched.
A browser attempt remained on the application's loading screen. Full visual,
light/dark and responsive verification could not be completed. Keyboard
activation, busy/disabled states and error recovery were exercised in component
tests. No separate web server was started or local environment configuration
changed to bypass the loading screen.

## Decisions and limits

- JSON configuration version 1 remains the envelope; ZIP contains that same
  `connector.json` and optionally inert `README.md`. Unknown versions fail.
- Existing routes, authorization middleware, import transaction, encrypted
  credentials and runtime SSRF controls are reused. No schema/database migration.
- Imports create copies in the current tenant and preserve activation. Review
  imported URLs, HTTP methods and tool instructions before making tools live.
- Validation is stricter than the former permissive v1 importer: unknown
  structural fields and unsafe inline authentication are rejected. Local identity
  metadata is explicitly discarded. Unsupported old files need adjustment to
  the documented contract rather than silent acceptance of ignored features.
- Every non-public shared header must be re-entered. Fixed authentication
  parameters become secret lookups in exports. Known credentials copied into
  public configuration block export; arbitrary unlabeled secret text cannot be
  identified reliably and must not be placed in public fields.
- ZIP needs ext-zip. JSON remains supported without it. ZIP uploads are limited
  to 5 MiB, with at most two entries and a maximum 100:1 compression ratio.
- No API reachability, endpoint correctness or successful live authentication
  is implied by import. The example uses an illustrative hostname. There is no
  new ingestion engine, automatic pagination, script execution or remote schema
  resolution.
