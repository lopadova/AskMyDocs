# Specifica del connettore Gmail API

Realizzare `padosoft/askmydocs-connector-gmail` nel repository `/Users/marco/packages/askmydocs-connector-gmail`, key `gmail`, namespace `Padosoft\\AskMyDocsConnectorGmail`, classi `GmailConnector` e `GmailServiceProvider`. Si applica integralmente [STANDARD.md](STANDARD.md). Il pacchetto legge Gmail personale e Google Workspace attraverso Gmail API v1, con OAuth per installazione e triage comune AskMyDocs.

## Struttura e riuso

Separare OAuth/refresh, trasporto Gmail, sync state, DTO messaggio/allegato, parser MIME/payload, filtro, policy allegati, Markdown renderer e metadata builder. Dipendenza base ^1.6; nessuna libreria IMAP. Usare Drive come riferimento OAuth, IMAP per comportamento email e Freshdesk per trasporto/resume. Non copiare i difetti elencati in ANALYSIS.md.

Config `connector-gmail.php`, letto/merge/publish sempre come `connector-gmail`. Client ID, secret e redirect URI server-side configurati per Gmail; non riusare implicitamente scope/token di Drive. Una connessione Gmail è un’installazione distinta, anche quando l’identità Google coincide.

## OAuth

Authorization endpoint Google, token endpoint `https://oauth2.googleapis.com/token`, flusso web server con state e accesso offline. Scope richiesti `https://www.googleapis.com/auth/gmail.readonly` e `openid` per l’identità stabile; non serve scope di invio/modifica/cancellazione. Il subject OIDC verificato è `email_mailbox_id`; motivare lo scope identità nel README.

Richiedere il consenso iniziale/reconnect quando serve ottenere refresh token, senza forzarlo a ogni sync. Validare scope concesso, preservare refresh token precedente se omesso nella risposta, gestire `invalid_grant` come reauthentication richiesta. Verificare ID token firma, issuer, audience, expiry e nonce del flusso; il subject stabile identifica la mailbox. `users.getProfile.emailAddress` resta un nome di presentazione: una rinomina non cambia keys/paths, mentre un subject diverso non deve riutilizzare stato/cursori precedenti.

Health legge un endpoint di profilo senza email download; disconnect cancella token e stato locale, documentando che revocare un grant Google condiviso può coinvolgere scope dello stesso OAuth client. Revoca remota solo se coerente con la separazione dei client.

Fonti: [OAuth web server](https://developers.google.com/identity/protocols/oauth2/web-server), [OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect), [scopes Gmail](https://developers.google.com/workspace/gmail/api/auth/scopes). Il README deve spiegare enable Gmail API, OAuth consent/client, redirect URI Herd compatibile con le regole Google e requisiti restricted-scope applicabili alla distribuzione; non cambiare APP_URL per aggirare il consenso.

## Labels e selezione

Usare `users.labels.list` per discovery e conservare mapping ID/nome; il contratto base restituisce lista stringhe, quindi round-trip degli ID label. Settings `folders.include/exclude` contiene label ID, non nomi o slug.

Semantica progettuale: include vuoto accetta tutte le label consentite; include non vuoto significa almeno una label inclusa; qualsiasi exclude vince. Spam e Trash esclusi per default con switch esplicito. Un messaggio senza INBOX può essere archiviato e resta importabile. Deduplicare un messaggio presente in più label.

Gmail `messages.list.labelIds` combina i filtri in AND: l’OR previsto dai settings richiede più query con dedup o un controllo client. Non sostituire la semantica configurata con quella dei parametri API. [API list messaggi](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/list).

Rinominare una label aggiorna la presentazione, non il path. Labels richieste non più disponibili producono uno stato/avviso esplicito, non fallback silenzioso a tutta la mailbox.

## Full sync e storia

Full: profile/labels, `users.messages.list` paginato e `users.messages.get` completo per ogni ID. Usare API base HTTPS ufficiale e endpoint allowlist. `nextPageToken` è continuation, `resultSizeEstimate` non è un conteggio certificato.

Persistenza batch finita e backfill riprendibile; nessun array con tutta la mailbox. Finestra `sync_window_days=0` significa intera storia. Predicati locali deterministici completano eventuali query provider, con date interne e fuso documentati.

Acquisire un anchor history prima della scansione, conservare il progresso full separato e riprodurre le modifiche dal suo anchor dopo la scansione. Non usare l’ultimo messaggio elaborato o il wall-clock come cursore globale. Testare arrivi/cancellazioni concurrent con la scansione.

Incremental: `users.history.list(startHistoryId=committedHistoryId)` paginato. Gestire `messagesAdded`, `messagesDeleted`, `labelsAdded`, `labelsRemoved`; deduplicare IDs e rileggere lo stato corrente dei messaggi modificati. `historyId` è una stringa opaca non contigua, non un intero PHP. Non leggere solo la generica lista `messages` se perde la distinzione degli eventi.

Il nuovo historyId si conferma solo alla conclusione durevole del giro. Continuation/history pending restano distinti; errore su un messaggio lascia un resume sicuro. HTTP 404 per storia scaduta avvia una full reconciliation riprendibile, senza cancellare documenti prima di completarla. 404 su singolo message-get ha semantica diversa e richiede riconciliazione scoped. [Guida sync](https://developers.google.com/workspace/gmail/api/guides/sync), [history.list](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.history/list).

History non applica gli stessi filtri di list: rivalutare il filtro corrente. Label/read/flag changes possono far entrare/uscire messaggi dalla selezione. Ampliare label/time window richiede backfill dei messaggi mai visti. Reconcile deletions locale opt-in, senza chiamare delete/trash/modify Gmail.

## Body e allegati

`format=full` e payload ricorsivo oppure `format=raw` con parser MIME verificato; il body deve essere completo. Decode base64url con padding e validazione; supportare charset, multipart/alternative e related, headers RFC codificati e content-disposition. `snippet` non basta.

Usare `users.messages.attachments.get` per `attachmentId`; rispettare allowlist/size/budget prima di scaricare quando il provider fornisce dimensioni. ID attachment oppure partId costituisce identità, mai il filename. Gestire inline/CID, same-name files, nested messages e body part senza allegato esterno. Unsupported devono risultare nella coverage.

Preservare native `id`, `threadId`, `historyId`, `internalDate`, labelIds, size estimate, RFC headers e metadata v1. La data di sync/filtro è `internalDate`; Header Date può essere preservato separatamente. Raw RFC822 opzionale e bounded; il triage usa l’originale Markdown acquisito e gli snapshot allegati previsti dal contratto.

Emettere `askmydocs.email.prepared.v1` prima della persistenza. Body e tutti gli allegati hanno la stessa chiave composta v1; label changes non modificano quella chiave. Il download resta read-only anche per i messaggi unread.

## Modifiche comuni dell’host assegnate alla chat Gmail

Lavorare in un worktree host dedicato da `origin/develop`, senza cambiare il checkout condiviso. Questa chat è l’unico proprietario dell’integrazione condivisa di Gmail e Microsoft Mail.

1. Listener sincrono del prepared-event, validazione envelope e `EmailTriageService::registerEnvelope` o equivalente; adapter IMAP mantiene l’attuale firma e identità.
2. Registro connettori email in host, supporto profili e `receive/identity` neutri con fallback IMAP. Non modificare connector-base installato.
3. Manifest receipts associati per slot/ID; replay di manifest già completati idempotente. Cleanup/rifiuti attachment API integrati senza applicare signature fixture IMAP ai provider API.
4. Campagne/preview/date/folders/parent/attachments e recovery generalizzati; UI comune e assistant disponibili per le due key; nessuna classificazione o UI duplicata nel pacchetto.
5. Discovery/registry/config/icone e test host; elenchi fissi aggiornati o derivati dai pacchetti realmente disponibili. Health/probe API non deve invocare IMAP.
6. Integrazione Composer locale con path repository per i due pacchetti quando esistono, senza update globale o riferimenti a release remote inesistenti. Ogni modifica dependency isolata e verificata; eventuali path assoluti locali non diventano configurazione di produzione committata.
7. Documentazione triage e developer guide corretta per queste modifiche, esempi di metadata/hook. Test parametrizzati IMAP/Gmail/Microsoft con fixture o fake adapters; quando il pacchetto Microsoft è pronto, eseguire smoke di discovery e ingest attraverso il bridge reale.

Aree host consentite: `app/Services/EmailTriage`, `app/Jobs/EmailTriage` se necessario, listener/provider/adapter connettori, `app/Connectors/HostIngestionBridge.php`, API/policy/UI connettori pertinenti, config, test e documentazione pertinenti, Composer/lock solo per integrazione mirata. Non toccare grounding/Kb investigation o altre modifiche preesistenti.

## Criteri specifici di accettazione

- Account multipli con token/cursori/paths isolati; scope readonly e OAuth state errato/ripetuto testati.
- Full multipagina, catch-up history concurrent, incremental added/deleted/label changes, 404 history recovery e filtri configurabili verificati.
- Crash dopo body/attachment/event o a metà pagina: rerun senza perdita di messaggi, duplicate decisioni o cursor advance prematuro.
- Messaggio multilabel singolo; label rename/move e unread invariati dopo fetch; no chiamate mutative email API nei test.
- Body multipart/base64url/charset, allegati omonimi/inline/unsupported/oversize con manifest e coverage corretti.
- Reconciliation non tocca altra installazione dello stesso account e non agisce su scan incompleto.
- Suite package e host pertinente verde con fake HTTP; Composer/Pint/PHPStan e CI presenti.
- Triage per entrambe le API conserva tutti i punti STANDARD.md e la regressione IMAP. Evidenza separata per Microsoft package integration se la sua chat sta ancora lavorando.
- Consegna commit locali package e host, path worktree, test ed eventuale OAuth/live non verificato; niente push/PR/tag/attivazioni mailbox.
