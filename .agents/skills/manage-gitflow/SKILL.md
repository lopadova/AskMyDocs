---
name: manage-gitflow
description: Gestisce il GitFlow di AskMyDocs in modo verificabile. Usare quando si crea o recupera un branch feature, si prepara una release, si applica un hotfix, si decide la destinazione di una PR, si esegue un rebase o si riallineano main e develop.
---

# Manage GitFlow

Applica la policy GitFlow del repository preservando il lavoro esistente e
rendendo espliciti base, destinazione delle PR e passaggi di riallineamento.

## Prima di operare

1. Leggi completamente `../../../docs/GITFLOW.md`.
2. Ispeziona branch corrente, stato, worktree, upstream, tag, divergenza e
   relazione di antenato tra `main` e `develop`.
3. Preserva file modificati o untracked non appartenenti al task.
4. Dichiara il tipo di flusso: feature, release, hotfix oppure recupero storico.
5. Non eseguire push, force-push, tag, cancellazioni remote o merge nei branch
   protetti se l'utente non li ha richiesti.

## Scegli il flusso

### Feature

- Parti da `develop` aggiornato.
- Usa `feature/<descrizione>`.
- Destina la PR a `develop`.
- Ribasa prima della PR solo se il branch non è condiviso o se la riscrittura è
  stata coordinata.

### Release

- Prima riallinea in `develop` gli hotfix presenti solo in `main`.
- Integra tutte le feature approvate in `develop` e attendi i gate verdi.
- Richiedi una versione definita e uno scope congelato prima di creare il ramo.
- Parti da `develop`, usa `release/X.Y.Z` e apri la PR verso `main`.
- Dopo il merge, tagga `main` e riporta i fix di release in `develop`.
- Non ricreare l'integrazione aprendo le singole feature direttamente verso la
  release.

### Hotfix

- Parti dall'ultimo `main` e usa `hotfix/X.Y.Z`.
- Apri la PR verso `main`, tagga il merge e riallinea subito `develop`.
- Se esiste una release attiva, applica o integra il fix anche lì.

### Recupero storico

- Confronta i commit con `develop` e individua squash merge equivalenti.
- Crea `backup/<nome>-pre-rebase-YYYYMMDD` prima di riscrivere.
- Rinomina con il prefisso GitFlow appropriato.
- Ribasa soltanto il tratto ancora necessario sopra `develop`.
- Mantieni intatto il vecchio remote finché l'utente non autorizza la pulizia.

## Verifica e consegna

1. Mostra la relazione prima/dopo tra i branch con commit e conteggi di
   divergenza.
2. Esegui i test proporzionati al cambiamento e `git diff --check`.
3. Verifica che nessun file dell'utente sia stato incluso per errore.
4. Per una release, segnala come blocker dipendenze locali, path assoluti,
   versioni `@dev`, CI rossa o hotfix non riallineati.
5. Riporta branch creati, commit, test, azioni remote eseguite e prossima PR.
