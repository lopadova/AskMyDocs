# Enhancement Progress Tracker

> Ogni agente, a fine task: aggiorna questa tabella + commit del file nel branch del task.
> Un PR NON è completo finché non si spunta "PR opened".

## Stato PR

| PR | Phase | Branch | Status | PR # | Branch parent | Ultimo aggiornamento | Note |
|----|-------|--------|--------|------|---------------|---------------------|------|
| 1  | A — Storage & Scheduler | `feature/enh-a-storage-scheduler` | ✅ PR opened | TBD | `origin/main` | 2026-04-23 | backend-only; 409 tests green |
| 2  | B — Auth JSON API + Sanctum SPA | `feature/enh-b-auth-api` | ✅ PR opened | TBD | PR1 | 2026-04-23 | 433 tests green; Sanctum stateful + 7 Api/Auth tests |
| 3  | C — RBAC foundation | `feature/enh-c-rbac-foundation` | ⏳ ready | — | PR2 | — | parte dal branch PR2 |
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

- [x] Checkout worktree sul branch `feature/enh-a-storage-scheduler` da `origin/main`
- [x] `config/filesystems.php` — blocchi r2/gcs/minio aggiunti, credenziali via env, commenti chiari
- [x] `config/kb.php` — `project_disks` map + `raw_disk` separato aggiunti
- [x] `app/Support/KbDiskResolver.php` — `forProject(string $projectKey): string`
- [x] `app/Console/Commands/PruneOrphanFilesCommand.php` — `kb:prune-orphan-files {--dry-run} {--disk=} {--project=}`
- [x] `bootstrap/app.php` — scheduler aggiunti:
  - [ ] `activitylog:clean --days=90` — **deferred to PR3** (spatie/laravel-activitylog non ancora installato) — TODO in-file
  - [ ] `admin-audit:prune --days=365` — **deferred to PR9** (tabella `admin_command_audit` arriva in PR9) — TODO in-file
  - [x] `queue:prune-failed --hours=48` at 04:00
  - [ ] `notifications:prune --days=60` — **skipped**: Laravel 13 non registra questo comando (solo `notifications:table`). Sostituzione consigliata: `model:prune --model=App\\Models\\DatabaseNotification` quando il model implementerà il trait `Prunable`. NOTE in bootstrap/app.php.
  - [x] `kb:prune-orphan-files --dry-run` at 04:40
- [x] `.env.example` — esempi r2/gcs/minio + `KB_PROJECT_DISKS` + `KB_RAW_DISK`
- [x] `tests/Feature/Kb/KbDiskResolverTest.php` — 10 test, default fallback, per-project override, env JSON parsing, canonical override
- [x] `tests/Feature/Commands/PruneOrphanFilesCommandTest.php` — 5 test (dry-run, normal, soft-delete awareness, delete-failure, per-project disk)
- [x] `vendor/bin/phpunit` → **409/409 verdi** (0 regressioni)
- [x] Aggiornato `LESSONS.md` con scoperte (notifications:prune, Mockery Storage, scheduler baseline)
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch, push, `gh pr create` verso `main`

### PR2 — Phase B checklist

- [x] Checkout worktree sul branch `feature/enh-b-auth-api` da `feature/enh-a-storage-scheduler`
- [x] `config/sanctum.php` — stateful domains parse da `SANCTUM_STATEFUL_DOMAINS`, guard `['web']`
- [x] `config/cors.php` — `supports_credentials=true`, paths `api/*` + `sanctum/csrf-cookie` + auth routes, origins parse da `CORS_ALLOWED_ORIGINS`
- [x] `config/auth.php` — declare `guards.sanctum` + `two_factor.enabled` flag
- [x] `.env.example` — `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `AUTH_2FA_ENABLED`
- [x] `app/Http/Requests/Auth/{Login,ForgotPassword,ResetPassword,TwoFactor}Request.php`
- [x] Refactor Blade `Auth/{Login,PasswordReset}Controller` per type-hint FormRequest
- [x] `app/Http/Controllers/Api/Auth/{Auth,PasswordReset,TwoFactor}Controller.php`
- [x] `routes/api.php` — gruppo `auth/*` con middleware `web`, throttle login (5/min) + forgot (3/min)
- [x] `AppServiceProvider::boot` — registra RateLimiter `login` + `forgot`
- [x] TestCase — registra `SanctumServiceProvider`, carica sanctum/cors/auth config
- [x] Test migrations — aggiunte `sessions` + `password_reset_tokens`
- [x] Tests `tests/Feature/Api/Auth/*Test.php` (22 test totali su 7 file)
- [x] `vendor/bin/phpunit` → **433/433 verdi** (0 regressioni, 24 test nuovi)
- [x] Aggiornato `LESSONS.md` con scoperte Phase B (Sanctum guard survive, defineRoutes + api prefix, etc.)
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch, push, `gh pr create` verso `feature/enh-a-storage-scheduler`

## Comando rapido per stato

```bash
grep -E "^\| [0-9]+" docs/enhancement-plan/PROGRESS.md
```
