---
id: STD-ACC-004
slug: access-password-standard
type: standard
status: accepted
retrieval_priority: 85
tags: [security, access, password, mfa]
owners: [security-team]
summary: Standard vigente per password e accessi - 14 caratteri, nessuna rotazione forzata, MFA per tutti.
related: []
---

# Standard password e accesso (vigente)

Questo standard sostituisce le regole precedenti (STD-ACC-002) per tutti i
dipendenti e i collaboratori di Northwind Logistics.

## Lunghezza e scadenza

La lunghezza minima è di 14 caratteri, senza obblighi di maiuscole o
simboli. Non esiste alcuna rotazione periodica forzata: la password cambia
solo in caso di sospetta compromissione.

## Controllo password compromesse

Ogni nuova password viene confrontata con un elenco di password compromesse
note e rifiutata se presente.

## Autenticazione a più fattori

L'MFA è obbligatoria per tutti i dipendenti per qualsiasi accesso agli
ambienti di produzione e alla VPN. Le passkey FIDO2 sono il metodo preferito.

## Blocco account

Dopo 5 tentativi falliti l'account viene bloccato per 15 minuti. Lo
sblocco anticipato richiede l'intervento del service desk.
