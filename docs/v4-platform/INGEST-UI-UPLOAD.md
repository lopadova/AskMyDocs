# Ingest via UI — drag-and-drop upload modal (team-switcher section)

**Branch:** `feature/team-switcher` (no new branch — section of the team switcher)
**Status:** implemented (backend + frontend + tests green; full PHPUnit suite 2300 ✓)
**Plan of record:** mirrors the approved working plan; this file is the git-tracked artefact.

---

## Goal

Let an admin upload files (drag-and-drop) from the KB admin pages into a chosen
project, and run ingest **reusing the exact Artisan pipeline**
(`DocumentIngestor` ← `IngestDocumentJob` ← `IngestDocumentFlow`). Files are
**staged** first, reviewed in a modal, then **moved to the final KB location and
ingested on confirm**, with **per-file progress** shown via polling. No logic is
duplicated; the modal is just a new caller of the same service path.

It is a **section tied to the global team (tenant) switcher** — every call is
tenant-scoped, every route lives under the authenticated admin group.

## Locked decisions

1. **Drop UX** — "Upload" button in the KB header (primary) **+** drop onto a
   folder/project node in the tree **when a single project filter is active**
   (no backend tree change). "All projects" → toast "select a project first" +
   button opens the modal in *picker-required* mode.
2. **File types v1** — `md`, `markdown`, `txt`, `pdf`, `docx` (the full
   `App\Support\Kb\SourceType::supportedMimes()` set).
3. **Async** — meaningful progress needs `QUEUE_CONNECTION=database` (or redis)
   + a running worker; documented. Default `sync` makes commit block (still
   correct, just synchronous). Test default stays `sync`.
4. **Canonical/git (mixed)** — upload **permits everything but warns**: staging
   detects canonical frontmatter (reuse `CanonicalParser`) and flags those items
   with a non-blocking warning ("this file will not be in git"). No block.

## Architecture

```
UI modale ── POST /uploads (multipart) ─► KbUploadStagingService::stage()
                                            • KbIngestBatch (status=staged)
                                            • file → kb-staging/{tenant}/{batch}/{item}.ext
                                            • CanonicalParser → is_canonical + warning
                                            • KbIngestBatchItem per file
          ── POST /uploads/{b}/commit ───► KbUploadStagingService::commit()
                                            • R21 atomic gate (lockForUpdate + committed_at)
                                            • per item: move kb-staging→kb (writeStream, R4)
                                            • IngestDocumentJob::dispatchForCurrentTenant(
                                                metadata=[kb_upload_batch_item_id]) ─► existing pipeline
          ── GET  /uploads/{b}/status ◄──  COUNT GROUP BY status (poll, stops at terminal)
                                            queue-event listener: queued→processing→succeeded/failed
                                            KnowledgeDocument::created observer fills knowledge_document_id
```

**Realtime seam (Phase 2):** every item-status mutation goes through
`KbUploadStagingService::transitionItem()` which emits `KbUploadItemStatusChanged`
(today a no-op event, no listener). Reverb later = implement `ShouldBroadcast`
on that event; zero refactor.

## Endpoints (admin group: `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`)

| Method | Route | Purpose |
|---|---|---|
| POST | `/api/admin/kb/uploads` | multipart: stage files → batch + items (201) |
| GET | `/api/admin/kb/uploads` | list recent batches (RBAC-matrix representative) |
| GET | `/api/admin/kb/uploads/{batch}` | inspect batch + items |
| DELETE | `/api/admin/kb/uploads/{batch}/items/{item}` | remove a staged file (only while `staged`) |
| POST | `/api/admin/kb/uploads/{batch}/commit` | R21 gate → move + dispatch (202) |
| GET | `/api/admin/kb/uploads/{batch}/status` | poll progress |
| POST | `/api/admin/kb/uploads/{batch}/cancel` | cancel a staged batch |

## Status lifecycle

- **Batch:** `staged → committing → processing → completed | completed_with_errors`; plus `cancelled`, `expired`.
- **Item:** `staged → moving → queued → processing → succeeded | failed`.

## R-rules touched

R1 (KbPath::normalize on every destination path) · R3 (chunkById in prune,
stream large files) · R4 (check every Storage put/move/delete return) · R7 (no
0777) · R18 (project options from tenant-scoped API) · R20 (FE payload ⇄ BE
validator) · R21 (commit gate atomic: lockForUpdate + committed_at in one txn) ·
R30 (tenant-scoped queries + binds) · R31 (new models BelongsToTenant +
TenantIdMandatoryTest) · R32 (route in AdminAuthorizationMatrixTest) · R11/R29
(testid hierarchy) · R15 (a11y) · R14/R17/R25 (surface failures, effect sync,
optimistic dedupe) · R12/R13 (E2E real data, only external provider stubbed) ·
R41 (teardown rollback before Mockery::close).

## Task checklist

- [x] **T0** Setup & tracking doc (this file).
- [x] **T1** `kb-staging` disk (`config/filesystems.php`) + `staging` block (`config/kb.php`) + `.env.example`.
- [x] **T2** Migrations `kb_ingest_batches` + `kb_ingest_batch_items`; models `KbIngestBatch`/`KbIngestBatchItem`; `TenantIdMandatoryTest` + `TenantReadScopeTest`.
- [x] **T3** `KbUploadStagingService::stage` + `KbUploadController::store` + `StageKbUploadRequest` + `POST /uploads`.
- [x] **T4** `commit` (R21 gate + move-then-dispatch) + `CommitKbUploadRequest` + `POST /uploads/{b}/commit`.
- [x] **T5** index / inspect / delete-item / status / cancel + routes + RBAC matrix.
- [x] **T6** `KbUploadBatchItemProgress` listener + `KnowledgeDocument::created` observer + `transitionItem` + `KbUploadItemStatusChanged` event seam.
- [x] **T7** Async queue + worker docs (README/deploy/.env.example).
- [x] **T8** `kb:prune-staging-batches` command + scheduler slot + `config/askmydocs.php` + `MaintenanceCommandController` description.
- [x] **T9** Frontend modal under `frontend/src/features/admin/kb/upload/` + KbView wiring.
- [x] **T10** Tests: PHPUnit (stage/commit/partial/idempotency R21/tenant R30/progress/prune/listener) + Vitest (dropzone) + E2E `kb-upload.spec.ts`. Full suite 2300 ✓, verify-e2e-real-data ✓, typecheck ✓.
- [ ] **T11** *(separate PR)* Realtime via Reverb (enabled by the T6 seam).

## Out of scope (future)

- Reverb realtime (T11). `retry-failed` endpoint (v2). `project_key` on folder
  tree nodes to allow drop in "All projects" view. ZIP/recursive-folder upload.
