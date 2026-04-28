---
name: review-pr-comments
description: Leggi, classifica e indirizza review comments di una pull request in modo sistematico.
---

# Review PR Comments

Workflow consigliato:

1. Raccogli PR, branch e commenti aperti.
2. Leggi sia review comments inline sia conversation comments.
3. Classifica ogni punto in:
   - obbligatorio
   - correttezza/performance
   - facoltativo
   - domanda
4. Proponi all'utente l'ordine di lavoro se ci sono molti commenti.
5. Implementa un fix per volta, con test mirato.
6. Crea commit chiari e tracciabili.
7. Prepara risposte brevi che spiegano la decisione o segnalano il fix.

## Regole

- Non partire dal primo commento ignorando gli altri.
- Non applicare un fix senza leggere il contesto del file.
- Non accorpare fix scollegati in commit vaghi.
- Se una richiesta implica refactor ampio o cambio comportamento, esplicitalo prima.

## Output

- elenco commenti classificati
- piano di fix
- file toccati e test eseguiti
- bozze di risposta ai reviewer
