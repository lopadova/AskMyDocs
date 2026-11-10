# Come ottenere l'accesso VPN

Guida per i dipendenti che devono collegarsi alla rete interna da fuori sede.

## Richiesta di accesso

Aprire un ticket nella categoria SEC-4471 indicando nome, reparto e motivo
dell'accesso. Il responsabile deve approvare entro 2 giorni lavorativi.

## Installazione del client

Il client si chiama NorthGate ed è disponibile nel portale software. Dopo
l'installazione importare il profilo aziendale e inserire il codice MFA.

## Certificato

Il certificato del dispositivo è valido 12 mesi. Il rinnovo parte 30 giorni
prima della scadenza ed è automatico se il dispositivo è gestito.

## File di configurazione

Su Linux il profilo si trova in `/etc/northgate/client.conf`. Non modificarlo
a mano: viene riscritto a ogni aggiornamento.

## Problemi comuni

Se la connessione cade ogni pochi minuti, verificare che non sia attiva una
seconda VPN. Per errori di autenticazione contattare il service desk.
