---
id: STD-ACC-002
slug: access-password-legacy
type: standard
status: deprecated
retrieval_priority: 10
tags: [security, access, password, legacy]
owners: [security-team]
summary: Standard password deprecato - rotazione ogni 90 giorni, 8 caratteri minimi, MFA solo per gli amministratori.
related:
  - "[[access-password-standard]]"
---

# Standard password e accesso (deprecato)

> Deprecato: sostituito da STD-ACC-004. Non usare per nuovi sistemi.

Questo standard regolava le password di dipendenti e collaboratori di
Northwind Logistics.

## Lunghezza e scadenza

La lunghezza minima era di 8 caratteri, con almeno una maiuscola, un numero
e un simbolo. La password doveva essere cambiata ogni 90 giorni e le ultime
5 password non potevano essere riutilizzate.

## Autenticazione a più fattori

L'MFA era obbligatoria solo per gli amministratori di sistema.

## Blocco account

Dopo 3 tentativi falliti l'account veniva bloccato per 30 minuti.
