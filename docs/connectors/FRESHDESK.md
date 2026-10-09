# Freshdesk: installazione locale e operatività

Il pacchetto vive in `/Users/marco/packages/askmydocs-connector-freshdesk`. L'app abilita provider, probe, strumenti chat e recupero storico soltanto quando la classe del pacchetto è disponibile. Il widget pubblico non espone questi strumenti.

## Installare senza modificare il manifest condiviso

Dalla cartella AskMyDocsDev:

```bash
php scripts/install-freshdesk-local.php
COMPOSER=composer.local.json composer update padosoft/askmydocs-connector-freshdesk --no-interaction
php artisan vendor:publish --tag=connector-freshdesk-assets
php artisan migrate --path=/Users/marco/packages/askmydocs-connector-freshdesk/database/migrations --realpath
npm run build
```

Lo script conserva gli eventuali override locali già presenti, aggiunge il repository `path` con symlink e tiene `composer.local.json` e `composer.local.lock` in `.git/info/exclude`. Non modifica `composer.json` o `composer.lock`. I successivi comandi Composer per questa installazione devono usare `COMPOSER=composer.local.json`; un normale `composer install` ripristina l'insieme condiviso delle dipendenze.

Aprire [AskMyDocsDev su Herd](https://askmydocsdev.test), scegliere **Connectors → Freshdesk**, indicare dominio `azienda.freshdesk.com`, API key, etichetta e progetto. **Test connection** verifica le credenziali; il salvataggio ripete la verifica prima di scrivere nel vault. La API key resta fuori da configurazione esportata e risposte.

OAuth per le API REST di Freshdesk non è disponibile, come confermato da [Freshworks](https://community.freshworks.dev/t/how-to-use-oauth-authentication-mechanism-for-freshdesk-api/1691); la [documentazione dell'autenticazione](https://developers.freshdesk.com/api/#authentication) richiede la API key. Gli esempi OAuth delle app Marketplace collegano servizi esterni. Fonti verificate l'8 ottobre 2026.

## Importazione ordinaria e storico

**Sync now** avvia una scansione iniziale dei ticket aggiornati negli ultimi 90 giorni e degli articoli pubblicati nella lingua predefinita, comprese le cartelle annidate. I successivi passaggi riprendono dal checkpoint temporale completato con una sovrapposizione. Cambiare il numero di giorni amplia o restringe la scansione, senza eliminare i documenti precedenti.

Nelle impostazioni dell'account, **Prendi tutto** avvia un recupero storico una tantum. La finestra ordinaria rimane invariata. L'azione espone stato, conteggi ed errori e riprende il medesimo recupero fallito. Durante un recupero attivo, una seconda richiesta restituisce il suo identificativo senza accodare una copia. I lotti usano un mutex per installazione.

Le note private sono incluse inizialmente. Disattivarle e avviare una nuova sincronizzazione aggiorna anche i ticket già importati dallo storico e rimuove i loro allegati privati dalla knowledge base. Le cancellazioni dei ticket devono comparire nel filtro upstream `deleted`; per gli articoli assenti viene verificato il dettaglio prima di rimuoverli.

I conteggi riguardano i documenti consegnati alla pipeline d'ingestione; indicizzazione ed estrazione possono proseguire nei job dell'app. Gli allegati supportati sono PDF, DOCX, TXT e Markdown; le immagini richiedono `kb.ocr.enabled`. Default: 25 MiB effettivi per file e 20 allegati per ticket.

## Code e scheduler

Il connettore richiede una coda asincrona e un cache store con lock atomici. Qui l'app usa Redis. La connessione `freshdesk` riusa Redis con `retry_after=660`; i lotti usano una coda dedicata `freshdesk`. Avviare il worker dei lotti oltre al worker di coordinamento già esistente (`connectors`):

```bash
php artisan queue:work freshdesk --queue=freshdesk --timeout=600 --tries=3
```

`CONNECTOR_FRESHDESK_QUEUE` e `CONNECTOR_FRESHDESK_CONNECTION` permettono di cambiare coda e connessione: mantenere `retry_after` maggiore del timeout del worker (600 secondi), evitando che i worker ordinari consumino la coda dedicata con una prenotazione più breve. Lo scheduler esistente avvia `StartSync` per Freshdesk: `last_sync_at` viene aggiornato soltanto a fine importazione. I timeout definitivi lasciano il recupero in errore, riprendibile dal suo checkpoint. Dopo le modifiche al codice, riavviare i worker secondo la gestione locale dell'app.

## Contratti dell'integrazione

Il registro `ConnectorInstallationActionRegistry` espone azioni generiche per connettore; `historical-import` è registrata per Freshdesk. Gli endpoint autenticati sono:

| Metodo | Endpoint | Risultato |
| --- | --- | --- |
| GET | `/api/admin/connectors/{id}/actions/historical-import` | Ultima importazione e conteggi, oppure `null` |
| POST | `/api/admin/connectors/{id}/actions/historical-import` | `202`, identificativo e stato del recupero |

Entrambi passano da `manageConnectors` e dal lookup dell'installazione nel tenant attivo. Le azioni sono pubblicate nei DTO delle installazioni e visualizzate con il componente condiviso `Button`.

`FreshdeskChatToolSource` implementa `ChatToolSourceContract`. La chat agentica registra gli strumenti come fonti `api`, con `source_runtime=freshdesk`, provenienza e conteggio di ogni tentativo HTTP. `api_route_id` rimane `null`, perché questi strumenti non rappresentano una rotta del connettore API generico. Catalogo ed esecuzione ricontrollano il progetto e l'installazione attiva.

## Verifica

```bash
vendor/bin/phpunit tests/Feature/Connectors/FreshdeskIntegrationTest.php
npm run test -- frontend/src/features/admin/connectors/ConnectorInstallationActions.test.tsx
npm run typecheck
```

I test d'integrazione Freshdesk sono condizionali quando il pacchetto opzionale manca. La suite autonoma del pacchetto verifica API simulate, sicurezza dei download, paginazione, ripresa e idempotenza.

Il collaudo reale richiede dominio e API key inseriti nel modulo dell'app. Lo storico comprende soltanto le risorse enumerabili: Freshdesk non permette di scoprire automaticamente tutti i ticket archiviati accessibili tramite ID noto. Una scansione che non riesce ad avanzare sul limite di paginazione rimane incompleta e mostra un errore. Riferimento: [API REST v2 Freshdesk](https://developers.freshdesk.com/api/).
