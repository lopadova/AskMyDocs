# GitFlow di AskMyDocs

Questo documento è la fonte normativa corrente per branch, pull request e
release di AskMyDocs. I documenti di release storici possono descrivere il
precedente modello `feature/vX.Y`; restano validi come cronologia, ma non devono
essere usati per nuove attività.

## Branch permanenti

- `main` rappresenta la produzione e contiene esclusivamente release concluse.
- `develop` integra il lavoro destinato alla prossima release e deve contenere
  sempre la storia di `main`.

Entrambi i branch sono protetti: niente commit diretti, cancellazioni o
force-push. Ogni modifica passa da pull request, CI verde, conversazioni risolte
e ciclo Copilot concluso. Non è richiesta un'approvazione umana obbligatoria,
così il maintainer unico non rimane bloccato.

## Matrice dei branch

| Lavoro | Parte da | Nome | PR verso | Merge |
|---|---|---|---|---|
| Feature | `origin/develop` | `feature/<descrizione>` | `develop` | Squash |
| Fix non urgente | `origin/develop` | `fix/<descrizione>` | `develop` | Squash |
| Manutenzione | `origin/develop` | `chore/<descrizione>` | `develop` | Squash |
| Release | `origin/develop` | `release/X.Y.Z` | `main` | Merge commit |
| Hotfix | `origin/main` | `hotfix/X.Y.Z` | `main` | Merge commit |
| Dependabot | configurato da GitHub | automatico | `develop` | Squash |

Una feature non apre mai una PR direttamente verso `main` o verso un branch di
release. Un branch `release/*` riceve soltanto correzioni di stabilizzazione e
documentazione della release in corso.

## Flusso feature

1. Aggiornare i riferimenti remoti con `git fetch --all --prune --tags`.
2. Creare il branch da `origin/develop`.
3. Mantenere commit locali piccoli e coerenti; non includere modifiche estranee.
4. Eseguire i gate pertinenti in locale.
5. Aprire la PR verso `develop` e completare CI e ciclo Copilot.
6. Usare squash merge e cancellare il branch soltanto dopo il merge verificato.
7. Creare ogni branch dipendente dal nuovo `origin/develop`, non dal branch
   precedente già squash-merged.

## Flusso release

1. Dichiarare il feature freeze e creare `release/X.Y.Z` da `origin/develop`.
2. Aggiornare changelog, README e release notes.
3. Accettare sul branch solo fix di stabilizzazione e documentazione.
4. Aprire `release/X.Y.Z → main` e completare tutti i gate di release.
5. Unire con merge commit.
6. Creare il tag annotato finale sul commit di `main` solo dopo il merge.
7. Aprire immediatamente `main → develop` e unire con merge commit, così
   stabilizzazioni e commit di release rientrano nel flusso di sviluppo.

I tag RC, quando richiesti, puntano a un commit verificato del branch
`release/X.Y.Z`; non sostituiscono la PR finale e non vengono creati
automaticamente a ogni milestone settimanale.

## Flusso hotfix

1. Creare `hotfix/X.Y.Z` da `origin/main`.
2. Applicare soltanto la correzione urgente e i test necessari.
3. Aprire la PR verso `main`, completare CI e Copilot e unire con merge commit.
4. Pubblicare il tag dalla nuova `main`.
5. Riportare immediatamente `main` in `develop` tramite PR con merge commit.

## Invarianti operative

- Prima di ricostruire o abbandonare un branch condiviso, creare un branch di
  backup e un bundle Git verificato.
- Usare `origin/main` e `origin/develop` come basi autorevoli; aggiornare i
  branch locali solo con fast-forward.
- Non riscrivere branch remoti condivisi e non usare force-push.
- Il push, la pubblicazione dei tag e le operazioni remote distruttive spettano
  al maintainer, salvo richiesta esplicita nel task corrente.
- I branch storici restano disponibili finché ogni modifica utile non è
  rappresentata da una PR integrata e verificata.
- `main` deve essere antenato di `develop` dopo ogni release o hotfix.

## Verifiche rapide

```bash
git fetch --all --prune --tags
git merge-base --is-ancestor origin/main origin/develop
git status --short --branch
git diff --check
```

Prima di una release eseguire anche installazioni pulite dai lockfile, suite PHP
e JavaScript complete, Playwright su tutti gli shard, build frontend e desktop,
e migrazioni forward/rollback su database pulito.
