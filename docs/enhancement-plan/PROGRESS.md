# Enhancement Progress Tracker

> Ogni agente, a fine task: aggiorna questa tabella + commit del file nel branch del task.
> Un PR NON è completo finché non si spunta "PR opened".

## Stato PR

| PR | Phase | Branch | Status | PR # | Branch parent | Ultimo aggiornamento | Note |
|----|-------|--------|--------|------|---------------|---------------------|------|
| 1  | A — Storage & Scheduler | `feature/enh-a-storage-scheduler` | ⏳ pending | — | `origin/main` | — | inizia qui |
| 2  | B — Auth JSON API + Sanctum SPA | `feature/enh-b-auth-api` | ⏳ blocked | — | PR1 | — | attende PR1 |
| 3  | C — RBAC foundation | `feature/enh-c-rbac-foundation` | ⏳ blocked | — | PR2 | — | |
| 4  | D — Frontend scaffold + auth pages | `feature/enh-d-frontend-scaffold` | ⏳ blocked | — | PR3 | — | |
| 5  | E — Chat UI React | `feature/enh-e-chat-react` | ⏳ blocked | — | PR4 | — | |
| 6  | F1 — Admin shell + Dashboard | `feature/enh-f1-admin-dashboard` | ⏳ blocked | — | PR5 | — | |
| 7  | F2 — Users & Roles | `feature/enh-f2-users-roles` | ⏳ blocked | — | PR6 | — | |
| 8  | G — KB Tree + Viewer + Editor | `feature/enh-g-kb-viewer-editor` | ⏳ blocked | — | PR7 | — | |
| 9  | H — Logs + Maintenance | `feature/enh-h-logs-maintenance` | ⏳ blocked | — | PR8 | — | |
| 10 | I — AI Insights | `feature/enh-i-ai-insights` | ⏳ blocked | — | PR9 | — | |
| 11 | J — Docs + E2E + polish | `feature/enh-j-docs-e2e-polish` | ⏳ blocked | — | PR10 | — | |

Legenda status: ⏳ pending / blocked · 🔨 in_progress · ✅ PR opened · 🎉 merged

## Checklist per PR corrente

Copiata dal template a inizio lavoro, spunta man mano.

### PR1 — Phase A checklist

- [ ] Checkout worktree sul branch `feature/enh-a-storage-scheduler` da `origin/main`
- [ ] `config/filesystems.php` — blocchi r2/gcs/minio aggiunti, credenziali via env, commenti chiari
- [ ] `config/kb.php` — `project_disks` map + `raw_disk` separato aggiunti
- [ ] `app/Support/KbDiskResolver.php` — `forProject(string $projectKey): string`
- [ ] `app/Console/Commands/PruneOrphanFilesCommand.php` — `kb:prune-orphan-files {--dry-run} {--disk=}`
- [ ] `bootstrap/app.php` — scheduler aggiunti:
  - [ ] `activity-log:prune --days=90` (solo se pacchetto installato, altrimenti skip)
  - [ ] `admin-audit:prune --days=365` (solo se tabella esiste)
  - [ ] `queue:prune-failed --hours=48`
  - [ ] `notifications:prune --days=60`
  - [ ] `kb:prune-orphan-files` 04:40
- [ ] `.env.example` — esempi r2/gcs/minio + `KB_PROJECT_DISKS`
- [ ] `tests/Feature/Kb/KbDiskResolverTest.php` — copre default fallback, per-project override, env parsing
- [ ] `tests/Feature/Commands/PruneOrphanFilesCommandTest.php` — `--dry-run` non cancella, modalità normale cancella orphan file
- [ ] `vendor/bin/phpunit` → tutti i test verdi
- [ ] Aggiornato `LESSONS.md` con eventuali scoperte
- [ ] Aggiornato `PROGRESS.md` → stato 🔨 → ✅
- [ ] Commit su branch, push, `gh pr create` verso `main`

## Comando rapido per stato

```bash
grep -E "^\| [0-9]+" docs/enhancement-plan/PROGRESS.md
```
