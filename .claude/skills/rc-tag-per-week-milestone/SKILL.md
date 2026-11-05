---
name: rc-tag-per-week-milestone
description: Prepares an AskMyDocs release-candidate tag on a frozen release/X.Y.Z branch under canonical develop/main GitFlow. Use only when an RC or release milestone is explicitly requested.
---

# Release candidate compatibility entrypoint

Leggi [`docs/GITFLOW.md`](../../../docs/GITFLOW.md) prima di preparare un tag.
Gli RC non sono più creati automaticamente alla chiusura di ogni milestone
settimanale e non vengono taggati su branch `feature/vX.Y`.

Quando viene richiesto esplicitamente un RC:

1. verifica che `release/X.Y.Z` sia nato da `origin/develop` dopo il feature
   freeze;
2. verifica CI, test, documentazione e release notes sul commit candidato;
3. cattura e registra lo SHA immutabile verificato;
4. incrementa `N` e prepara `vX.Y.Z-rcN` su quello SHA;
5. non pubblicare tag o release senza autorizzazione esplicita.

La release finale confluisce da `release/X.Y.Z` a `main` con merge commit; il
tag stabile viene creato sulla `main` risultante e `main` viene poi riallineato
in `develop`. I package indipendenti mantengono il proprio ciclo SemVer.
