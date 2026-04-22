# RESUME: come riprendere dopo crash sessione

Se sei un agente Claude Code invocato "a freddo" in una nuova sessione, leggi questa guida TUTTA prima di fare qualunque cosa.

## 1. Contesto della richiesta utente

L'utente (Lorenzo Padovani, padosoft) ha approvato un piano di potenziamento enterprise per AskMyDocs. Il piano è in `docs/enhancement-plan/00-ORCHESTRATOR.md`. Si tratta di **11 PR sequenziali** (Phase A → J) da mergeare su `main`, recensiti uno dietro l'altro alla fine.

Il design visivo è già fatto (bundle Claude Design in `docs/enhancement-plan/design-reference/`). Stack: Laravel 13 + React 19 + TS + Tailwind + Sanctum + Spatie permission.

## 2. Percorso per capire dove sei

```bash
# 1. Sei nel worktree giusto?
pwd
# Deve essere C:\Users\lopad\Documents\DocLore\Visual Basic\AskMyDocs-enh

# 2. Su quale branch?
git branch --show-current
# feature/enh-orchestrator (safe/state) oppure feature/enh-X-slug (PR in corso)

# 3. Qual è lo stato di avanzamento?
cat docs/enhancement-plan/PROGRESS.md
# Cerca il PR con status 🔨 in_progress o il prossimo ⏳ pending

# 4. Cosa hanno imparato gli agenti precedenti?
cat docs/enhancement-plan/LESSONS.md

# 5. Qual è il piano dettagliato?
cat docs/enhancement-plan/00-ORCHESTRATOR.md
```

## 3. Scenari

### A) Nessun PR è ancora in corso (tutti ⏳)

Sei il primo orchestratore. Vai su `00-ORCHESTRATOR.md` → PR1, crea branch `feature/enh-a-storage-scheduler` da `origin/main`, dispatcha un agente di implementazione per Phase A.

### B) Un PR è 🔨 in_progress

Leggi la sezione "Checklist per PR corrente" in `PROGRESS.md`. Leggi l'ultimo commit sul branch in corso (`git log --oneline -10`). Identifica cosa manca dalla checklist e continua da lì.

### C) Un PR è ✅ PR opened ma non mergiato

Lascia quel PR in pace (sta aspettando review utente). Passa al PR successivo se non è bloccato. Crea branch dal branch del PR aperto (NON da main): la catena è sequenziale.

### D) Il merge conflitta

Rebase sul branch parent aggiornato. Se il parent è `main` e main è avanzato (altra PR canonical mergeata), fai `git fetch origin main && git rebase origin/main`. Se il parent è un altro branch enh-* che è stato mergeato su main, fai `git rebase origin/main` anche su questo.

## 4. Regole che non devono mai cambiare

1. **Non toccare mai** il worktree principale (`Visual Basic/Ai/AskMyDocs`) — l'altra sessione canonica lavora lì.
2. **Non force-push** nulla.
3. **Non `git add -A`** — aggiungi file esplicitamente.
4. **Non saltare hook** (`--no-verify`, `--no-gpg-sign`).
5. **Ogni PR** deve avere test verdi PRIMA del push.
6. **Rispetta R1–R10** in CLAUDE.md di AskMyDocs.
7. **Clean code rules** utente: one-level indent, no else, bad-path-first, return early.

## 5. Come apro il PR una volta finito

```bash
# sul branch del PR
git push -u origin feature/enh-X-slug

gh pr create \
  --title "feat(enh): Phase X — short title" \
  --body "$(cat <<'EOF'
## Summary
<cosa fa questo PR in 1-3 bullet>

## Phase
X di 11 del piano `docs/enhancement-plan/00-ORCHESTRATOR.md`.

## Checklist regole
- [ ] R1 KbPath::normalize usato se tocco path
- [ ] R2 soft-delete aware se tocco KnowledgeDocument queries
- [ ] R3 memory-safe bulk se ho loop su tabelle grandi
- [ ] R4 no silent failures
- [ ] R6 docs allineati al codice
- [ ] R7 no 0777, no @-silence
- [ ] R8 KB_PATH_PREFIX rispettato se walk-ano file su disco
- [ ] R9 docs match code
- [ ] R10 canonical-awareness se tocco kb_nodes/edges/frontmatter_json

## Test plan
- [ ] vendor/bin/phpunit verde
- [ ] (se tocca frontend) npm run build + npm run test verdi
- [ ] Verifica end-to-end manuale: <descrivere>

## Parent branch
PR parte da `<parent-branch>`. Per riprodurlo localmente:
\`\`\`bash
git checkout <parent-branch>
git checkout -b feature/enh-X-slug
\`\`\`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

## 6. Aggiornamento state files a fine task

```bash
# Aggiorna PROGRESS.md: cambia status da 🔨 a ✅, aggiungi PR# e data
# Aggiorna LESSONS.md: aggiungi sezione "## PR-N — Phase X (<agent-name>, <date>)"
git add docs/enhancement-plan/PROGRESS.md docs/enhancement-plan/LESSONS.md
git commit -m "$(cat <<'EOF'
docs(enh): update progress + lessons for Phase X

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
git push
```

## 7. Chi è l'utente e cosa si aspetta

- **Autonomia totale** (modalità auto): non chiedere permesso per decisioni ordinarie.
- **Interrompe** se vede qualcosa che non va: rispondi subito e aggiorna il piano.
- **Revisionerà** tutti i PR in coda alla fine, uno per uno.
- **Stack preferito**: Laravel "puro", no Livewire, no Inertia. React + TS + Tailwind per UI. Nessun altro framework.
- **PDF export**: Browsershot. **RBAC**: Spatie + custom tables. **Auth SPA**: Sanctum cookie-based stateful.
