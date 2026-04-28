# Regola: Evitare git worktree di default

Non usare `git worktree` come scelta predefinita per parallelizzare lavoro AI o sviluppo.

Preferire:

- branch separati
- test mirati
- code review incrementale
- task paralleli sullo stesso working tree se il rischio di conflitto e' basso

Usare `git worktree` solo se c'e' un motivo concreto:

- rilasci paralleli realmente indipendenti
- maintenance branch di lunga durata
- vincoli di tooling che richiedono checkout multipli
