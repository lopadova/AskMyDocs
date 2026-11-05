---
name: branching-strategy-feature-vx
description: Compatibility entrypoint for AskMyDocs branching questions. Routes current work to the canonical develop/main GitFlow policy and prevents reuse of the historical feature/vX.Y integration model.
---

# Branching strategy compatibility entrypoint

Questo percorso è conservato per i link e le automazioni meno recenti. Il
precedente modello con un branch di integrazione `feature/vX.Y` non è valido per
nuovo lavoro.

Leggi [`docs/GITFLOW.md`](../../../docs/GITFLOW.md) e applica queste regole:

- `feature/*`, `fix/*` e `chore/*` partono da `origin/develop` e hanno PR verso
  `develop`;
- `release/X.Y.Z` parte da `origin/develop` e ha PR verso `main`;
- `hotfix/X.Y.Z` parte da `origin/main`, confluisce in `main` e viene riportato
  immediatamente in `develop`;
- i tag finali vengono creati sul commit pubblicato di `main`;
- i branch storici condivisi non vengono riscritti: si conservano come sorgenti
  e si recuperano in PR funzionali.

I documenti delle release concluse restano record storici e non sostituiscono
la policy corrente.
