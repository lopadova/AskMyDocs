# Next release — v8.36.0

Stato di pianificazione: 9 settembre 2026.

## Baseline

- Release in produzione: `v8.35.0`, commit `51dd1b35` su `main`.
- `develop` deve essere riallineato con `main` prima di integrare nuovo lavoro.
- Ramo sorgente di recupero: `feature/mcp-update`, commit `e30e52bf`.
- Backup locale: `backup/mcp-update-pre-split-20260909` più bundle completo.
- Package MCP attesi: connector MCP `^1.0`, MCP pack `^2.0`.

## Sequenza di integrazione

1. Riallineare `develop` con `main` tramite merge commit.
2. Integrare la governance GitFlow.
3. Retargettare e integrare verso `develop` gli aggiornamenti Dependabot:
   Composer minor/patch, npm minor/patch, quindi Vitest 5.
4. Stabilire la baseline dei due package MCP con update Composer mirato.
5. Estrarre il ramo sorgente in PR funzionali, una alla volta, ricreando ogni
   branch dal nuovo `origin/develop` dopo il merge della PR precedente.
6. Eseguire i gate completi e preparare `release/8.36.0`.

Il commit `093586a3` non deve essere cherry-pickato: contiene aggiornamenti
Composer non controllati. Lockfile, file condivisi e migrazioni vengono
ricostruiti manualmente nella PR responsabile.

## Gate di release

- PHPUnit completo.
- Vitest completo senza timeout o test ignorati.
- Playwright completo su quattro shard.
- Installazione Composer pulita dal lockfile.
- Build frontend e desktop.
- Migrazioni forward e rollback su database pulito.
- Security-rule validation, typecheck e ciclo Copilot senza finding aperti.

La PR finale è `release/8.36.0 → main` e usa merge commit. Il maintainer crea il
tag annotato `v8.36.0`, quindi riallinea immediatamente `main → develop`.
