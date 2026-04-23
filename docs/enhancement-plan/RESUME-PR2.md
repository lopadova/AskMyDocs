# RESUME PR2 — riparti da qui dopo riavvio PC

> **Data pausa:** 2026-04-23
> **Ultimo PR completato:** PR #16 — https://github.com/lopadova/AskMyDocs/pull/16 (Phase A, merged? verifica)
> **Prossimo step:** PR2 — Phase B: Auth JSON API + Sanctum SPA stateful

## 1. Dove sei (stato verificato al momento della pausa)

- **Worktree enhancement:** `C:\Users\lopad\Documents\DocLore\Visual Basic\AskMyDocs-enh`
- **Branch corrente del worktree:** `feature/enh-orchestrator` (safe, ospita solo state files)
- **Branch PR1 (appena completato):** `feature/enh-a-storage-scheduler` — 5 commit ahead di origin/main, pushato, PR #16 aperto
- **Branch parent per PR2:** `feature/enh-a-storage-scheduler` (la catena continua da qui, NON da main)

## 2. Cosa fare alla ripartenza — copy/paste

Apri una nuova sessione Claude Code nella cartella del worktree principale o in quello enhancement. Incolla questo prompt:

```
Riprendi l'orchestrazione AskMyDocs Enhancement da PR2 (Phase B — Auth API + Sanctum SPA).
Il worktree è C:\Users\lopad\Documents\DocLore\Visual Basic\AskMyDocs-enh
PR1 (#16) è chiuso e aperto su GitHub. Leggi:
- docs/enhancement-plan/00-ORCHESTRATOR.md (master plan, sezione PR2)
- docs/enhancement-plan/PROGRESS.md (stato)
- docs/enhancement-plan/LESSONS.md (apprendimenti PR1 da leggere PRIMA di toccare codice)
- docs/enhancement-plan/RESUME-PR2.md (questo file)
poi procedi: git fetch, checkout feature/enh-a-storage-scheduler, crea feature/enh-b-auth-api, dispatch agente PR2.
```

## 3. Comandi esatti per preparare il branch PR2

Una volta ripartita la sessione, l'orchestratore (o tu) deve eseguire:

```bash
WT="C:/Users/lopad/Documents/DocLore/Visual Basic/AskMyDocs-enh"

# 1. Aggiorna tutti i branch remoti
git -C "$WT" fetch origin --prune

# 2. Verifica PR #16 (opzionale — se vedi "MERGED" la catena è già su main)
gh pr view 16 --json state,mergedAt 2>&1

# 3. Checkout del branch parent (PR1) e crea il branch PR2
git -C "$WT" checkout feature/enh-a-storage-scheduler
git -C "$WT" pull origin feature/enh-a-storage-scheduler
git -C "$WT" checkout -b feature/enh-b-auth-api

# 4. Verifica state files presenti (devono esserci i 4 file in docs/enhancement-plan/)
ls "$WT/docs/enhancement-plan/"
# Expected: 00-ORCHESTRATOR.md, LESSONS.md, PROGRESS.md, RESUME.md, RESUME-PR2.md, design-reference/

# 5. Dispatch agente PR2 (vedi briefing nel §4 sotto)
```

## 4. Briefing per l'agente PR2 — pronto da incollare

L'orchestratore ripartito dovrà chiamare `Agent` con `subagent_type: general-purpose`, `mode: acceptEdits`, `run_in_background: true`, e questo prompt (adatta il nome del branch se serve):

```
Sei l'implementation agent per PR2 — Phase B: Auth JSON API + Sanctum SPA stateful.

Working dir: C:\Users\lopad\Documents\DocLore\Visual Basic\AskMyDocs-enh
Branch: feature/enh-b-auth-api (già checked out, branchato da feature/enh-a-storage-scheduler)
NON toccare il worktree C:\Users\lopad\Documents\DocLore\Visual Basic\Ai\AskMyDocs.

# Leggi PRIMA (in ordine)
1. docs/enhancement-plan/00-ORCHESTRATOR.md → sezione "PR2 — Phase B"
2. docs/enhancement-plan/LESSONS.md → sezione "PR1 — Phase A" (apprendimenti chiave)
3. CLAUDE.md → R1–R10
4. config/sanctum.php, config/cors.php, config/auth.php (stato attuale)
5. app/Http/Controllers/Auth/LoginController.php, PasswordResetController.php (validazione esistente da riusare)
6. routes/web.php, routes/api.php (dove si innestano i nuovi gruppi)

# Scope (condensato — dettagli in 00-ORCHESTRATOR.md sezione PR2)

Obiettivo: esporre login/logout/me/forgot/reset come JSON API + Sanctum stateful SPA. Mantenere i Blade esistenti per fallback.

## Lavoro
1. config/sanctum.php → stateful domains via SANCTUM_STATEFUL_DOMAINS
2. config/cors.php → supports_credentials=true, paths: api/*, sanctum/csrf-cookie, login, logout
3. Nuovi controller in app/Http/Controllers/Api/Auth/:
   - AuthController@login (JSON {user,abilities} o 422)
   - AuthController@logout (204)
   - AuthController@me ({user, roles:[], permissions:[], projects:[], preferences:{}})
   - PasswordResetController@forgot (JSON mirror del Blade)
   - PasswordResetController@reset (JSON mirror del Blade)
   - TwoFactorController stub (enable/verify/disable — feature flag AUTH_2FA_ENABLED=false, ritorna 501 Not Implemented se disabilitato)
4. FormRequest in app/Http/Requests/Auth/{Login,Forgot,Reset,TwoFactor}Request.php
   IMPORTANTE: i Blade controller esistenti devono condividere la validazione via questi FormRequest (refactor leggero).
5. Throttle: login 5/min per IP+email, forgot 3/min per IP
6. routes/api.php → gruppo Route::prefix('auth')->middleware('web')->group(...)
7. Tests: tests/Feature/Api/Auth/{Login,Logout,Me,Forgot,Reset,TwoFactorStub}Test.php (happy path + 422 + throttle)

## Verifiche (note da LESSONS.md di PR1)
- Laravel 13 auto-registra EnsureFrontendRequestsAreStateful sul group 'web' → non devi aggiungerlo a mano
- Verifica vendor/: se manca, `composer install` nel worktree (PR1 l'ha già fatto ma solo sul suo branch; controlla con `ls vendor/` al root del worktree)
- Auto-discovery dei command NON è abilitato in questo repo: per i NUOVI controller/route va tutto via registration esplicita, ma questo non ti riguarda (route sono in routes/api.php)
- `vendor/bin/phpunit` è il test runner; il php shim `php.bat` mappa a php84

## Commit strategy (5 commit)
1. feat(enh-b): sanctum stateful SPA config + CORS credentials
2. feat(enh-b): FormRequests auth (Login/Forgot/Reset/TwoFactor)
3. feat(enh-b): JSON auth controllers + routes + throttles
4. test(enh-b): auth API end-to-end tests
5. docs(enh-b): update progress + lessons for PR2

# Output finale
- PR URL (gh pr create --base feature/enh-a-storage-scheduler --head feature/enh-b-auth-api ...)
  NOTA: --base punta al branch parent (PR1), NON a main, perché la catena è sequenziale e l'utente revisionerà in coda.
- phpunit summary
- Append a docs/enhancement-plan/LESSONS.md sezione "## PR2 — Phase B (..., 2026-XX-XX)"
- Update docs/enhancement-plan/PROGRESS.md (PR2 → ✅, PR3 → ⏳ ready)

# Clean code (user's house rules — NON NEGOZIABILI)
- Un solo livello di indent per metodo
- Nessun `else` (early return / bad path first)
- Return early quando possibile

# Regola git
- Mai git add -A (add esplicito dei file)
- Mai force-push
- Mai --no-verify
- Commit con HEREDOC e trailer Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>

Parti subito, niente plan mode.
```

## 5. Dopo che PR2 è chiuso

Replicare il pattern su `feature/enh-c-rbac-foundation` branchando da `feature/enh-b-auth-api`. E così via fino a PR11. Vedi `00-ORCHESTRATOR.md` per la catena completa.

## 6. Se qualcosa va storto alla ripartenza

- **Worktree sparito:** ricrea con `git worktree add -B feature/enh-orchestrator "C:/Users/lopad/Documents/DocLore/Visual Basic/AskMyDocs-enh" origin/feature/enh-orchestrator` dal worktree principale.
- **Branch PR1 non visibile:** `git fetch origin --prune` e poi `git checkout -B feature/enh-a-storage-scheduler origin/feature/enh-a-storage-scheduler`.
- **PR #16 è stato mergeato mentre eri via:** cambia il parent di PR2 a `main`: crea `feature/enh-b-auth-api` da `origin/main` invece che da PR1. La catena si appiattisce a mano a mano che i PR si mergiano.
- **File di stato persi localmente ma presenti su GitHub:** `git pull origin feature/enh-orchestrator`.

## 7. TaskCreate status al momento della pausa

```
#1 PR1 Phase A — completed (PR #16 opened)
#2 PR2 Phase B — pending (ready to start, blocked=none)
#3–#11 — pending (attendono la catena)
```
