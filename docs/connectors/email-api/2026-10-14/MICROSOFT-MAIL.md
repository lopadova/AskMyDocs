# Specifica del connettore Microsoft Mail API

Realizzare `padosoft/askmydocs-connector-microsoft-mail` nel repository `/Users/marco/packages/askmydocs-connector-microsoft-mail`, key `microsoft-mail`, namespace `Padosoft\\AskMyDocsConnectorMicrosoftMail`, classi `MicrosoftMailConnector` e `MicrosoftMailServiceProvider`. Si applica integralmente [STANDARD.md](STANDARD.md). Leggere posta Microsoft 365/Exchange Online e Outlook.com via Microsoft Graph v1.0, con OAuth per installazione e contratto comune del triage.

Questa chat modifica esclusivamente il pacchetto Microsoft. La chat Gmail possiede le modifiche comuni dell’host per entrambi i provider. Implementare e testare subito il contratto v1 tramite listener/bridge Testbench; non aspettare la consegna host per sviluppare il pacchetto e non modificare classi, frontend, Composer o lockfile AskMyDocs.

## Struttura e riuso

Separare OAuth/refresh, Graph transport, mailbox/folder discovery, per-folder sync state, DTO messaggio/allegato, filtro, policy, renderer e metadata builder. Base ^1.6; nessuna dipendenza da IMAP o dal connettore OneDrive. OneDrive è una fonte per OAuth/Graph, IMAP per comportamento email e Freshdesk per transport/resume.

Config `connector-microsoft-mail.php`, stesso root per letture/merge/publish. Application/client ID, secret, redirect URI e authority sono server-side. Distinguere directory/tenant OAuth Microsoft dal tenant AskMyDocs e dall’identità della mailbox. Non riusare token o scope OneDrive implicitamente.

## OAuth e mailbox

Authorization-code Microsoft identity platform v2 con state monouso e PKCE S256. Authority configurabile tra tenant organizzativo, organizations, consumers e common, con validazione stretta; nessun host arbitrary. Il tipo dell’app registration deve coincidere con gli account dichiarati supportati.

Default delegato: `offline_access` e `Mail.Read`; aggiungere `User.Read` solo se necessario a risolvere `/me` e identità account. Scope OIDC utili soltanto se effettivamente impiegati. `Mail.ReadBasic` non è sufficiente al contenuto richiesto dal prodotto. Refresh mantiene token/extra/cursori e memorizza refresh token ruotato. Auth/consent error rendono visibile la necessità di riconnettere.

Mailbox default `/me`, identificata tramite ID account persistente e directory Microsoft; UPN/mail address sono dati di presentazione, non key. Supportare anche mailbox condivisa Microsoft 365 tramite `/users/{id}` come opzione esplicita, con ID target persistente verificato e scope delegato `Mail.Read.Shared` quando richiesto. Un indirizzo eventualmente inserito va risolto a identità persistente prima di creare lo stato; se il flusso non può risolverlo con i permessi concessi, richiedere l’ID target e spiegare l’errore. Se non consentita, errore permission esplicito; non fallback alla mailbox personale. Gli account Outlook.com restano personali. App-only/client-credentials e accesso a tutte le mailbox non fanno parte della prima consegna.

Health verifica identità/accesso target senza ingest. Disconnect elimina le credenziali locali e non revoca sessioni globali dell’utente o consenso Entra con privilegi amministrativi.

Fonti: [authorization code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [permessi Graph](https://learn.microsoft.com/en-us/graph/permissions-reference). Il README deve spiegare app registration, tipi account, redirect URI Herd, scope e consenso, shared mailbox e limiti di policy aziendale.

## Identità e cartelle

Su tutte le richieste messaggi/allegati/delta inviare `Prefer: IdType="ImmutableId"` e mantenere la preferenza anche seguendo nextLink/deltaLink. IDs case-sensitive e conservati come stringhe; non decodificare o cambiare case. Immutable ID rimane stabile per move nella stessa mailbox, mentre archive mailbox/export-reimport possono cambiare identità. [Guida immutable ID](https://learn.microsoft.com/en-us/graph/outlook-immutable-id).

Discovery ricorsiva di `/me/mailFolders` e `childFolders`, o equivalente target users. Gestire paginazione a ogni livello, nomi duplicati e hidden folders con scelta esplicita. `listAvailableFolders` restituisce ID cartella; mapping percorso/nome resta nel pacchetto. Settings include/exclude sono ID, non displayName o slug.

Default tutte le cartelle consentite eccetto Deleted Items e Junk Email, con opzioni per includerle. Usare gli identificativi well-known disponibili per riconoscerle, non i nomi localizzati. Inbox, Sent Items, Archive e cartelle personalizzate sono selezionabili. Rename della cartella cambia presentazione; nuova cartella selezionata richiede baseline propria.

Un messaggio spostato mantiene la chiave parent v1 e i percorsi. Ogni folder scan/delta deve aggiornare la membership prima di decidere la rimozione locale. Il folder ID non fa parte dell’identità della mailbox.

## Sync per cartella

Usare `/me/mailFolders/{id}/messages/delta`, oppure lo stesso sotto `/users/{mailboxId}`, per baseline e incremental. Una mailbox richiede stato separato per ogni cartella. Conservare mapping folder e version/fingerprint config; non esiste un singolo deltaLink valido per tutta la mailbox.

Seguire `@odata.nextLink` integralmente come continuation opaca e salvare `@odata.deltaLink` come checkpoint committed solo quando l’intero giro è durevole. Non ricostruire `$skiptoken` o cambiare select/filtri durante il giro. Se il batch termina per limite, conservare nextLink/progresso e distinguere baseline incomplete da baseline completate.

`$select` limita i dati e le richieste successive get completano body/headers quando necessario. Il delta ha limiti su `$filter/$orderby` e non supporta `$search`; non promettere query arbitrarie. La guida riporta un limite di 5.000 messaggi quando si usa il filtro delta receivedDateTime: per full history o per garantire la completezza usare baseline senza quel filtro e applicare la finestra lato client, oppure un algoritmo alternativo con prova di completezza. [Delta messaggi](https://learn.microsoft.com/en-us/graph/delta-query-messages).

Errore su un messaggio, put, listener o dispatch impedisce il cursor commit della pagina. Invalid/expired token o reset delta ripartono con baseline/reconciliation solo della cartella coinvolta. Distinguerli da 404 sul messaggio e da folder cancellata; non prunare su errori o baseline incompleta. [Delta overview](https://learn.microsoft.com/en-us/graph/delta-query-overview).

Una voce `@removed` dal delta cartella può corrispondere a uscita/spostamento: prima di cancellare il parent, risolvere current location tramite immutable ID oppure completare reconciliation delle cartelle selezionate. In un move tra cartelle incluse, un ciclo per la sorgente non deve eliminare il documento prima di importare la destinazione. Reconciliation locale opt-in, scoped alla chiave composta e mai delete/move/mark-read upstream.

Read/flag/category/folder updates rivalutano filtri e metadati senza pagare nuove decisioni se body/manifest sono invariati. Configurazione allargata richiede baseline/backfill delle cartelle o intervallo appena inclusi.

Validare tutti i continuation URL HTTPS `graph.microsoft.com`, path v1.0 e mailbox/account target prima di inoltrare bearer. Non seguire referenceAttachment URLs o redirects su host esterni. Gestire 429 Retry-After e 5xx con limiti finiti; nessuna attesa infinita nel sync worker. [Throttling](https://learn.microsoft.com/en-us/graph/throttling).

## Corpo e allegati

Recuperare `body` completo, non `bodyPreview` o solo `uniqueBody`; usare preferenza body text/html coerente con settings e renderer. `internetMessageHeaders` va richiesto quando serve threading/filtri. Preservare subject, sender/from, To/Cc/Bcc disponibili, received/sent/lastModifiedDateTime, internetMessageId, conversationId, parentFolderId, categories, importance, flag e isRead.

`GET .../messages/{id}/$value` può fornire MIME completo; se usato, parsing e dimensioni restano bounded. Non è obbligatorio scaricare una seconda copia raw quando i campi API soddisfano acquisizione e snapshot. [Get message](https://learn.microsoft.com/en-us/graph/api/message-get?view=graph-rest-1.0).

Gestire `/messages/{id}/attachments` paginato e distinguere:

| Tipo | Comportamento richiesto |
| --- | --- |
| `fileAttachment` | Bytes via contentBytes/base64 oppure `$value`, policy MIME/size/inline, ID/slot stabile |
| `itemAttachment` | Contenuto Outlook annidato acquisito/renderizzato se supportato, con depth/bytes budget; altrimenti excluded con motivo visibile |
| `referenceAttachment` | Metadati/link come coverage esclusa; non scaricare URL né chiedere scope OneDrive aggiuntivi |

Inline attachments possono richiedere discovery anche se `hasAttachments` non segnala file ordinari. Testare il caso; evitare perdere file necessari alla coverage. Non fidarsi del filename come identificatore. [Get attachment](https://learn.microsoft.com/en-us/graph/api/attachment-get?view=graph-rest-1.0).

Applicare lo stesso envelope prepared/manifest/hash/receipt del contratto comune prima di qualsiasi persistenza. Source namespace `microsoft_mail`, key connector `microsoft-mail`; metadata e `email_message_key` invarianti nei move. Il body usa receivedDateTime come `email_date`, preservando le altre date nei campi provider.

## Settings

Gruppi comuni più authority/account type e mailbox target solo dove serve. Nessuna configurazione endpoint arbitrario o permessi di scrittura. Folder include/exclude, sync window, unread/flagged, mittente/destinatari/oggetto e auto-generated, body format/quoted text/PII, policy allegati/budgets, hidden folders e reconciliation opt-in.

Health e discovery devono funzionare dall’host senza codice IMAP. Un eventuale campione API è read-only e non cambia cursor/config o attiva AI. Mantenere account/work-school/shared permission errors distinti da mailbox vuota.

## Criteri specifici di accettazione

- OAuth e refresh con rotation/extra preservation; target personale e shared mailbox con permessi espliciti, assenza di fallback su target non consentito.
- ImmutableId header presente su tutte le richieste, inclusi continuation/get/attachments; key/path invarianti nei move.
- Discovery folder ricorsiva paginata, omonimi/localizzazione/hidden folders e change config con nuova baseline.
- Full history multipagina e per-folder delta, nextLink/deltaLink scoped e protetti, invalid cursor recovery e crash a metà batch senza skip.
- Move tra due cartelle incluse non causa perdita/duplicazione; uscita scope e reale deletion seguono reconciliation configurata. Scan incompleto non cancella.
- Body completo/headers, file/item/reference/inline attachments e omonimi, byte/depth limits, metadata e manifest corretti.
- Prepared-event sincrono verificato prima di put/dispatch, listener failing non conferma cursore, replay idempotente. Nessuna dipendenza App/IMAP.
- PHPUnit/Pint/PHPStan/Composer validate e CI compatibilità presenti, fake HTTP con richieste mutative proibite; niente mailbox reali o AI pagata nei test.
- README OAuth/sync/triage/multi-account e limiti reali; consegna commit locali/test/path. Separare successo package da integrazione host dipendente dalla chat Gmail e dal live OAuth non verificato.
