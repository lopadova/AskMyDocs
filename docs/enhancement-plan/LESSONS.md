# Lessons Learned (pass-through fra agenti)

> Ogni agente aggiunge qui tutto ciò che ha scoperto durante l'implementazione e che
> può risparmiare tempo all'agente successivo. Ordine cronologico (più recente in alto).

---

## Bootstrap (orchestrator, 2026-04-23)

- **Worktree path:** `C:\Users\lopad\Documents\DocLore\Visual Basic\AskMyDocs-enh` (branch `feature/enh-orchestrator` è la zona safe, NON toccarlo — è qui solo per state).
- **Tutti i PR** girano su branch dedicati nel worktree, partendo dal branch del PR precedente.
- **Sessione parallela** sul worktree principale `Visual Basic/Ai/AskMyDocs` sta ancora lavorando su `feature/kb-canonical-phase-7` (merged PR #15, ma potrebbe esserci follow-up). **Mai toccare quel filesystem.**
- **PHP shim:** la user-memory dice che `php` è rimappato a `php84` tramite `php.bat`. PHPUnit via `vendor/bin/phpunit`.
- **Clean-code rules** (user memory): one-level indent, no else, bad-path-first, return early. **Rispettali sempre.**
- **Gitmoji style:** commit style del repo è `feat(area): short`, `fix(area): short`. Niente gitmoji, solo type(scope). Vedi `git log --oneline -20`.
- **Co-author trailer:** richiesto su ogni commit autonomo.
- **Design bundle** da Claude Design è in `docs/enhancement-plan/design-reference/`. Il layout UX/UI è vincolante: dark-first glassmorphism, violet→cyan, Geist Sans/Mono. Porta i tokens.css as-is in `frontend/src/styles/tokens.css` e usali a fianco di Tailwind (non convertire tutto in classi Tailwind — inline styles + tokens sono già ottimi).
- **Skill attive** per questo repo (da CLAUDE.md): R1 kb-path-normalization, R2 soft-delete-aware-queries, R3 memory-safe-bulk-ops, R4 no-silent-failures, R6 docs-match-code, R7 no-world-writable, R8 kb-path-prefix, R9 docs-match-code, R10 canonical-awareness. **Rispetta tutte** in ogni PR.
- **Main branch** è a `be42931` (Merge PR #15 canonical phase 7). Fetched e confermato.

---

## Template per nuova entry

```
## PR-N — Phase X (agent-name, YYYY-MM-DD)

- Scoperta 1: cosa, perché rilevante, dove vederla
- Trappola evitata: X che sembrerebbe Y ma non è
- Comando/snippet utile: `...`
- File-chiave toccati: `path/to/file.php`
- Raccomandazione per il prossimo PR: ...
```
