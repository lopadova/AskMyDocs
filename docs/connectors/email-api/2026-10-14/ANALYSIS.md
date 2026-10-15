# Analisi dei pacchetti connettori email API

Data: 14 ottobre 2026. Base host verificata: `f2a9e133a5f79a1ba3965654f9db1e5c2dd9f17c`, `origin/develop` di AskMyDocsDev.

Gmail e Microsoft Mail saranno due librerie Composer indipendenti. Acquisizione, autenticazione, sincronizzazione e metadati appartengono ai pacchetti; il triage resta nel modulo email di AskMyDocs. Entrambi devono usare lo stesso contratto di preregistrazione e lo stesso percorso di indicizzazione, mantenendo compatibili le email IMAP già importate.

## Standard effettivo dei pacchetti

| Area | Fonte verificata | Conseguenza per i nuovi pacchetti |
| --- | --- | --- |
| Contratti e discovery | `vendor/padosoft/askmydocs-connector-base/src/{ConnectorInterface,BaseConnector,ConnectorRegistry}.php` nell’host | Usare namespace `Padosoft`, discovery Laravel e `extra.askmydocs.connectors`; nessuna dipendenza da classi `App` |
| Separazione host | `docs/adr/0009-v46-connector-package-extraction.md`, `Contracts/ConnectorIngestionContract.php` nel base | Risolvere il bridge pubblico per paths, redazione, dispatch, audit e cancellazioni |
| Versioni | `composer.json` dell’host e dei pacchetti IMAP, Freshdesk, Google Drive e OneDrive | PHP ^8.3, Illuminate ^12.0\|^13.0, base ^1.6; Testbench ^10.0\|^11.0 e PHPUnit ^11.0\|^12.0 |
| Form e selezione contenitori | `Contracts/{SupportsConnectionSettings,SupportsFolderDiscovery}.php` nel base; `docs/adr/0022-v825-schema-driven-connector-settings.md` | Settings dichiarativi; discovery delle label Gmail e cartelle Graph gestita dai pacchetti |
| OAuth | `Auth/OAuthCredentialVault.php` nel base; pacchetti Drive/OneDrive | State monouso per installazione, token cifrati, refresh, credenziali separate da config |
| Metadati | `Support/Metadata/SourceAwareMetadataBuilder.php` nel base; `askmydocs-connector-imap/src/Support/MailMetadata.php` | Origine provider preservata, `converter_hints` e `_derived` coerenti, email normalizzate come `text/markdown` |
| Trasporto e ripresa | `askmydocs-connector-freshdesk/src`, `askmydocs-connector-imap/src/ImapConnector.php`, host `ImapSyncProgressContext.php` | HTTP verificabile, limiti finiti, resume durevole e conferma solo dopo dispatch riuscito |
| Test e distribuzione | `composer.json`, `tests`, `.github/workflows` dei pacchetti citati | Testbench senza account reali, PHPUnit/Pint/PHPStan, documentazione e CI di compatibilità |

Il percorso `/Users/marco/packages/askmydocs-connector-base` non esiste. La copia disponibile è quella installata sotto `AskMyDocsDev/vendor/padosoft/askmydocs-connector-base`. Va letta per i contratti, mai modificata per implementare funzionalità. La cartella `askmydocs-connector-template` contiene soltanto README e licenza: non è uno scaffolding completo.

`docs/connectors/README.md` descrive ancora il modello v4.5 con classi `App\\Connectors`. Le firme e i namespace correnti nei sorgenti e nell’ADR 0009 prevalgono sugli esempi storici. Drive e OneDrive pubblicano anche config che non coincide sempre con il root letto durante l’esecuzione: i nuovi pacchetti devono avere un unico root coerente per merge, lettura e publish.

## Funzioni email da recuperare

Il pacchetto IMAP locale è `/Users/marco/packages/askmydocs-connector-imap`.

| Componente | Comportamento da preservare |
| --- | --- |
| `src/Imap/ImapMessage.php` | Sender, destinatari, data, oggetto, body plain/HTML, headers, threading, flag/label, allegati; sostituire UID/UIDVALIDITY con identità native API |
| `src/Imap/EmailToMarkdown.php` | Intestazioni leggibili, body completo, HTML ripulito e convertito, preferenza plain, stripping citazioni configurabile e disabilitabile |
| `src/Imap/MessageFilter.php` | Mittenti/destinatari anche per dominio, oggetto include/exclude, unread/flagged, auto-generated; exclude prevale su include |
| `src/Imap/AttachmentPolicy.php` e `config/imap.php` | Allowlist, limiti bytes/numero, inline, motivo di esclusione; default attuali 25 MiB, 20 allegati, inline esclusi |
| `src/Support/MailMetadata.php` | Origine, account, headers, Message-ID, In-Reply-To, References, data, label/cartella, allegati e search tags |
| `src/ImapConnector.php` | Schema settings, full/incremental, stato persistito e reconciliation configurabile |
| Host `app/Connectors/Imap/Backfill/ImapBackfillImporter.php` | Import storico riprendibile, percorsi con hash/slot, controllo write e ricevute |

Riutilizzare il comportamento e i test utili. Non introdurre una dipendenza runtime da `webklex/php-imap` o dal pacchetto IMAP. Se viene copiato un helper puro, adattarlo ai DTO API, conservarne l’attribuzione Apache 2.0 e aggiungere test delle differenze.

## Triage già disponibile

Fonti principali: `docs/email-triage.md`, `app/Services/EmailTriage`, `app/Jobs/EmailTriage/AnalyzeEmailJob.php`, `tests/Feature/Connectors/EmailTriageTest.php`, `EmailTriageAssistantTest.php` e `tests/Unit/Decisions/EmailTriageEngineTest.php` nell’host.

Il modulo offre già profili revisionati e opt-in, assistente per configurare regole, decisioni Jev combinate, carta automatica e tag di ricerca, coverage, snapshot immutabili, queue dedicata, retry idempotenti, campagne retroattive con preview e costi, pause/resume e versioning con ACL. Il download non deve eseguire la classificazione AI.

Il manifesto allegati viene registrato prima della persistenza. Il body resta in attesa fino alle ricevute del body e di tutti gli allegati accettati; gli allegati seguono comunque l’ingest normale. Una decisione salvata viene riusata quando fallisce l’indicizzazione. L’originale del triage è il Markdown acquisito dal connettore e verificato con hash, non necessariamente un file RFC822.

### Accoppiamenti IMAP da generalizzare

| Punto host | Accoppiamento | Intervento necessario |
| --- | --- | --- |
| `TriageProfiles::supports` | Solo `connector_name === 'imap'` | Registro centrale dei connettori email con fallback IMAP |
| `EmailTriageService::register` | Parametri `ImapMessage`, `AttachmentPolicy`, builder IMAP | Aggiungere registrazione da envelope v1; mantenere `register` legacy come adattatore |
| `EmailTriageService::receive` e `identity` | Solo IMAP e `imap_doc_key` | Supporto chiavi email native; algoritmo legacy invariato |
| `TriageCampaigns` | Query `imap_*` per data/folder/parent/allegati | Accessori/query neutri con fallback IMAP |
| `HostIngestionBridge` | Pulizia immagini rifiutate solo IMAP | Ricevute e cleanup API; mantenere le verifiche fixture/UID specifiche IMAP |
| `ConnectorEmailProbeService`, `PilotEmailTriageCommand` | Client IMAP costruito direttamente | Non esporre un test email API che chiama IMAP; health/discovery OAuth funzionanti e diagnostica API separata |
| Test registry e UI connettori | Elenchi fissi e disponibilità triage | Scoprire i pacchetti installati e mostrare il pannello comune per entrambi |

## Difetti da non propagare

1. `HostIngestionBridge::softDeleteByRemoteId` filtra il tenant ma non distingue tutte le installazioni dello stesso account. Le nuove chiavi devono includere tenant, installazione, mailbox e ID provider; body e allegati condividono la chiave parent.
2. `OAuthCredentialVault::setCredentials` sostituisce `extra_json`. Il refresh deve preservare tutte le extra key; i cursori vanno aggiornati granularmente.
3. Un errore per messaggio non può essere seguito dall’avanzamento oltre quel messaggio. Nei nuovi connettori page/history/delta checkpoint avanzano solo dopo ricevute durevoli complete oppure dopo un’esclusione intenzionale registrata.
4. Il filename non identifica un allegato e lo slug non identifica una cartella. Usare ID/slot/hash stabili e mantenere i nomi soltanto per presentazione.
5. Gmail label multiple e Graph move tra cartelle non devono duplicare o eliminare prematuramente lo stesso messaggio.

## Decisioni comuni per le due implementazioni

| Pacchetto | Composer | Key | Namespace |
| --- | --- | --- | --- |
| Gmail | `padosoft/askmydocs-connector-gmail` | `gmail` | `Padosoft\\AskMyDocsConnectorGmail` |
| Microsoft Mail | `padosoft/askmydocs-connector-microsoft-mail` | `microsoft-mail` | `Padosoft\\AskMyDocsConnectorMicrosoftMail` |

Il contratto email v1 in [STANDARD.md](STANDARD.md) usa metadati condivisi e un evento Laravel sincrono `askmydocs.email.prepared.v1`. È un nuovo contratto di progetto: l’host attuale deve implementare il listener. Non richiede una patch a `vendor` o la pubblicazione preliminare di connector-base.

La chat Gmail possiede anche l’unico adattatore host e l’integrazione comune di entrambi i connettori. La chat Microsoft modifica solo il proprio pacchetto. La verifica di integrazione finale per Microsoft dipende dalla consegna host della chat Gmail, mentre sviluppo e test del pacchetto procedono autonomamente contro il contratto v1.

## API ufficiali

Gmail usa full sync e cronologia `history.list`; una cronologia scaduta richiede una nuova full sync. La lettura di body e allegati usa `gmail.readonly`, classificato da Google come restricted. L’accesso offline è gestito dall’OAuth web-server. Fonti: [sync Gmail](https://developers.google.com/workspace/gmail/api/guides/sync), [scopes](https://developers.google.com/workspace/gmail/api/auth/scopes), [OAuth](https://developers.google.com/identity/protocols/oauth2/web-server).

Microsoft Graph usa delta per cartella e link opachi di continuazione. Gli immutable ID evitano cambi di identità nei move dentro la stessa mailbox. Il consenso delegato `Mail.Read` serve anche gli account personali; `Mail.Read.Shared` riguarda gli account work/school. Fonti: [delta messaggi](https://learn.microsoft.com/en-us/graph/delta-query-messages), [immutable ID](https://learn.microsoft.com/en-us/graph/outlook-immutable-id), [permessi](https://learn.microsoft.com/en-us/graph/permissions-reference).

Le specifiche provider distinguono comportamenti delle API, decisioni progettuali e criteri di accettazione. Il collaudo su account reali resta distinto dai test con fixture.
