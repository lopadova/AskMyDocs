---
name: manage-gitflow
description: Gestisce feature, release, hotfix e recuperi storici di AskMyDocs usando i branch permanenti develop e main. Usare quando si crea o riallinea un branch, si decide la base di una PR, si prepara una release o si recupera un ramo condiviso.
---

# Manage GitFlow

Leggi prima [`docs/GITFLOW.md`](../../../docs/GITFLOW.md): è la fonte normativa
corrente. I documenti storici possono mostrare il vecchio modello
`feature/vX.Y`, ma non lo rendono valido per il lavoro nuovo.

## Scegliere base e destinazione

- Feature, fix non urgenti e manutenzione: parti da `origin/develop` e apri la
  PR verso `develop`.
- Release: crea `release/X.Y.Z` da `origin/develop`, apri la PR verso `main` e
  usa merge commit.
- Hotfix: crea `hotfix/X.Y.Z` da `origin/main`, apri la PR verso `main`, poi
  riallinea subito `main` in `develop`.
- Dependabot deve avere `target-branch: develop` per ogni ecosistema.

Usa squash merge per feature, fix e chore. Usa merge commit per sync, release e
hotfix. I tag finali appartengono a `main`.

## Lavorare in sicurezza

1. Esegui `git fetch --all --prune --tags`.
2. Usa i riferimenti `origin/*` come basi autorevoli.
3. Controlla working tree, upstream, divergenze e commit unici prima di agire.
4. Prima di ricostruire un ramo condiviso, crea un backup nominato e un bundle
   completo verificato.
5. Non modificare o riscrivere la sorgente finché il recupero non è concluso.
6. Non eseguire push, tag, merge remoto, cancellazioni o force-push senza una
   richiesta esplicita nel task corrente.

Quando un branch viene recuperato, ricostruisci PR funzionali da
`origin/develop`; porta manualmente le modifiche pertinenti ai file condivisi e
crea commit atomici. Attendi il merge di una PR dipendente prima di creare la
successiva dalla nuova base remota.

## Gate

Esegui i gate richiesti dal diff e da `docs/GITFLOW.md`. Prima di dichiarare una
release pronta, verifica inoltre che `main` sia antenato di `develop`, che il
tag punti alla `main` pubblicata e che nessuna modifica non rilasciata sia
entrata involontariamente in produzione.
