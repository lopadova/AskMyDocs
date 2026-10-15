# Standard comune dei connettori email API

Questi requisiti valgono per Gmail e Microsoft Mail. Le specifiche provider aggiungono OAuth, endpoints e cursori nativi. Le chat devono realizzare pacchetti installabili e testati; il triage deve funzionare attraverso il modulo comune AskMyDocs senza duplicare classificatori, profili o UI.

## Pacchetto e confini

- Repository locali separati: `/Users/marco/packages/askmydocs-connector-gmail` e `/Users/marco/packages/askmydocs-connector-microsoft-mail`.
- Libreria Apache 2.0 con PHP ^8.3, `illuminate/contracts`, `http`, `support` ^12.0|^13.0 e `padosoft/askmydocs-connector-base` ^1.6. Aggiungere solo dipendenze runtime effettivamente necessarie, con constraint verificati.
- PSR-4 `src/` e `tests/`, `declare(strict_types=1)`, Laravel ServiceProvider e config pubblicabile. Discovery tramite `extra.laravel.providers` e `extra.askmydocs.connectors`.
- Implementare tutte le firme reali di `ConnectorInterface`, preferibilmente estendendo `BaseConnector`, più `SupportsConnectionSettings` e `SupportsFolderDiscovery`.
- Implementare `DeclaresProvenance::provenanceTier(int $installationId)` con default `ProvenanceTier::UntrustedExternal`. Un override interno richiede scelta esplicita per installazione; non inferire fiducia da login OAuth o dominio del mittente. Gli allegati ereditano scope/ACL del parent e la carta triage dichiara la provenienza automatica senza promuovere l’autorità dell’email.
- Dipendere solo da contratti del base e componenti Laravel. Nessun riferimento runtime a `App`, `KnowledgeDocument`, job host o IMAP. Non modificare `vendor`.
- Un unico root config, rispettivamente `connector-gmail` e `connector-microsoft-mail`, usato da merge, publish e letture. Le impostazioni di installazione sono in `config_json`; segreti e token soltanto nel vault cifrato.
- `src/`, `config/`, `resources/icons/`, `tests/{Unit,Feature,Support,Fixtures}`, `.github/workflows/ci.yml`, `composer.json`, README, CHANGELOG, LICENSE e guide OAuth/sync/triage sono consegne minime.
- Icone SVG reali, con nome accessibile; per UI host seguire button system AskMyDocs, shadcn-svg-icons e skill mobile applicabili.

## Autenticazione e isolamento

OAuth authorization-code, state monouso legato all’installazione e tenant, PKCE S256 dove supportato dal flusso scelto. Callback rifiuta errori provider, state mancante/scaduto/ripetuto o di altra installazione. Validare lo scope realmente concesso prima di segnare una connessione sana.

Il refresh preserva refresh token quando non viene restituito un sostituto e preserva `extra_json`. Serializzare il refresh per installazione per evitare rotazioni concorrenti. Cursori e stato non devono cambiare durante health/discovery, salvo refresh delle credenziali. Nessun client singleton conserva token o contesto di un’altra installazione.

Health deve essere breve, distinguere autenticazione fallita da indisponibilità e non scaricare/ingestire email. Disconnect elimina credenziali e stato con audit, con eventuale revoca strettamente coerente con il provider; non richiede cancellare il consenso di altri prodotti.

L’ingest passa tenant e progetto espliciti dal modello installazione. Paths, stato, lock e tombstones sono separati per tenant/installation/mailbox. Non sostituire la mailbox con una stringa derivata dall’email del mittente.

## Metadati email v1

Campi comuni obbligatori sul body e su ogni allegato:

| Campo | Tipo e significato |
| --- | --- |
| `connector` | `gmail` oppure `microsoft-mail` |
| `installation_id` | Intero dell’installazione |
| `email_schema_version` | Intero `1` |
| `email_provider` | `gmail` oppure `microsoft-mail` |
| `email_mailbox_id` | Identità persistente dell’account/mailbox, non il nome di una cartella |
| `email_message_id` | ID nativo provider case-sensitive, conservato come stringa |
| `email_message_key` | Chiave parent composta, descritta sotto |
| `email_folder_ids` | Lista di ID label Gmail oppure ID cartella Graph |
| `email_folder_names` | Lista di nomi leggibili, usata per presentazione/filtri |
| `email_date` | ISO8601 o null; data di ricezione interna quando disponibile |
| `email_attachment_count` | Totale allegati osservati, inclusi quelli esclusi |
| `email_body_hash` | SHA256 dei bytes Markdown acquisiti che saranno persistiti, dopo la redazione richiesta e prima della carta triage |
| `email_revision_hash` | Hash deterministico body+manifesto semantico, distinto dagli stati mutabili delle ricevute |

Calcolo congelato della chiave:

```php
$identity = [$tenantId, $installationId, $connectorKey, $mailboxId, $nativeMessageId];
$emailMessageKey = $connectorKey.':'.hash('sha256', json_encode(
    $identity,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
));
```

`tenantId`, `connectorKey`, `mailboxId` e `nativeMessageId` sono stringhe; `installationId` è un intero. Label/cartella, subject, flags e indirizzi non partecipano alla chiave. Gli allegati conservano la stessa `email_message_key` del body e aggiungono `email_attachment_id` e `email_attachment_slot`, oltre ai campi legacy di collegamento `attachment_filename` e `attachment_of_message_id`.

`gmail_doc_key` oppure `microsoft_mail_doc_key` può essere un alias della chiave parent; la cancellazione deve usare `email_message_key`, mai un ID raw. Usare `SourceAwareMetadataBuilder`: sorgenti `gmail` e `microsoft_mail`, metadati nativi sotto `converter_hints`, segnali `_derived` coerenti in entrambe le proiezioni. Preservare threading RFC Message-ID/In-Reply-To/References, sender, To/Cc e Bcc quando disponibile, date, labels/folder, flag e numero allegati. Gli ID non sono tag automatici di ricerca.

Paths deterministici con installation e hash della chiave, non subject/email/filename: un body per messaggio; allegati con ID/slot oltre al nome sanificato. Lo spostamento o cambio label non crea un nuovo body. Il MIME body consegnato al bridge è `text/markdown`; gli allegati conservano il MIME reale supportato.

## Preregistrazione triage

Ogni pacchetto emette sincronicamente l’evento Laravel `askmydocs.email.prepared.v1`, con un solo argomento envelope, dopo fetch/filtri/policy e prima di scrivere o dispatchare qualsiasi body/allegato. Si usa il dispatcher Laravel ordinario; il listener host non deve implementare `ShouldQueue`.

```php
Event::dispatch('askmydocs.email.prepared.v1', [[
    'schema_version' => 1,
    'tenant_id' => $tenantId,
    'installation_id' => $installationId,
    'project_key' => $projectKey,
    'metadata' => $bodyMetadata,
    'email_date' => $isoDateOrNull,
    'manifest' => $manifest,
]]);
```

Il manifesto è una lista ordinata di slot `{slot, id, name, mime, hash, status, reason}`. `slot` è un intero stabile per quella revisione; `id` è l’identità attachment/part provider. Gli accettati hanno SHA256 dei bytes che saranno persistiti e stato `pending`; gli esclusi `hash=null`, `status=excluded` e motivo esplicito. Se la pipeline applica trasformazioni ai bytes, l’hash deve riferirsi al contenuto effettivamente ricevuto dal bridge. Il parent non può essere reso ready usando solo un conteggio.

Prima di assegnare slot, ordinare gli allegati per identità provider con confronto stringa case-sensitive, usando un ID/part path sintetico deterministico solo quando manca l’ID nativo. Il renderer prepara il Markdown definitivo e chiama la redazione prevista prima dell’evento. Calcolo congelato della revisione, con gli stessi flag JSON della chiave:

```php
$manifestIdentity = array_map(static fn (array $f): array => [
    $f['slot'], $f['id'], $f['name'], $f['mime'], $f['hash'],
    $f['status'] === 'excluded' ? $f['reason'] : null,
], $manifest);
$emailRevisionHash = hash('sha256', json_encode(
    [$emailBodyHash, $manifestIdentity],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
));
```

L’evento porta i due hash in `metadata`, e ogni ricevuta body/allegato li mantiene identici. Il listener conserva i terminali solo per la stessa revisione; una revisione nuova ha ricevute distinte, senza riutilizzare allegati conclusi di contenuto diverso. Rifiutare ricevute obsolete e impedire a job di una vecchia revisione di proiettare sull’originale nuovo. Snapshot paths e stati saved/pending/refused non entrano nell’hash di revisione.

Il listener valida schema, installazione, connector key, tenant, progetto e coerenza dei metadati. Se il triage è spento la registrazione è un no-op. Se è acceso, salva il manifesto prima di ritornare. Un errore del listener si propaga: nessun cursor advance. In un altro host senza listener, il normale `ConnectorIngestionContract` resta utilizzabile; quel consumer deve implementare il contratto per offrire triage.

Il body e ogni allegato passano poi attraverso il bridge con gli stessi metadati parent. La ricevuta dell’allegato si associa a ID/slot e hash, così due file omonimi non collidono. Stato `saved`, esclusione da estrazione o rifiuto OCR esplicito non bloccano la decisione; un errore reale di fetch/write/dispatch impedisce il completamento del batch.

Replay completo prima di cursor commit deve riusare manifesto/evaluation già conclusi senza riaprire stati terminali o duplicare AI/ingest. Non avanzare cursori sulla sola riuscita del fetch.

## Acquisizione e allegati

- Recuperare body completo; snippet/preview non sono il documento. Preferenza plain configurabile, HTML→Markdown senza script/style/head o fetch remoti. Supportare multipart, charset, headers codificati, quote e contenuti mancanti senza inventare testo.
- Riprendere i filtri IMAP: finestra temporale, mittente/destinatari/email/domini, subject include/exclude, unread/flagged, auto-generated. Semantica documentata, exclude prevale. Un filtro attivo con dati mancanti non accetta silenziosamente.
- Riprendere policy allegati: enabled, allowlist, max bytes per file, max count, skip inline. Default compatibili IMAP: 25 MiB/file, 20 file, inline esclusi. Aggiungere budget totale messaggio e batch per non caricare in RAM una mailbox.
- Filename/ID remoti non sono paths fidati. Gestire traversal, nomi duplicati, MIME discordante, inline/CID e allegati vuoti. Non scaricare reference URL o risorse nel body.
- Snapshot RFC822 facoltativo con limiti e spazio dichiarato; lo snapshot normalizzato richiesto dal triage resta immutabile e distinto dall’eventuale raw MIME.
- Chiamare `redactContent` secondo la policy host. Non redigere in place i bytes originali della mailbox. La redazione AI esistente resta nell’engine del triage.

## Sincronizzazione e recupero

Full sync iniziale e backfill storico riprendibile, incremental tramite cursore nativo. `syncFull` può lavorare in batch finiti ma deve mantenere uno stato esplicito di completamento: una pagina limitata non rappresenta tutta la mailbox.

Persistenza e dispatch durevoli precedono i checkpoint. Il re-run della stessa pagina è idempotente. Nessun errore intermedio seguito da cursor advance oltre elementi falliti; memorizzare continuation separata dal cursore committed. Lease per installazione/mailbox, time budget, max pages/messages/bytes, retry finiti con jitter e osservabilità dei limiti.

Ripetere body o decisioni solo se cambia il contenuto/manifesto o il profilo. Flag/read/label changes devono aggiornare i metadati senza classificazione pagata inutile. Una selezione basata su campi mutabili richiede riconciliare ingresso e uscita dal filtro.

Cambi di config invalidano/riscopano i cursori e rilanciano la discovery necessaria; ampliare una finestra temporale o label/cartelle include deve recuperare la storia appena inclusa. Cursori di altri account/config non sono riusabili.

La reconciliation locale dei messaggi cancellati o fuori scope è opt-in e ha semantica documentata. Rimuovere body e allegati via bridge solo dopo prova di cancellazione o assenza definitiva dallo scope selezionato. Full rescan incompleto o errore API non autorizzano un prune. Mai mutare la mailbox upstream per effettuare triage o sync.

HTTP timeouts e retry bounded; distinguere auth/permission da 429, quota e 5xx. Rispettare Retry-After. Validare host, scheme, path e account scope dei link di continuazione prima di inoltrare Authorization; disabilitare redirect con bearer verso host esterni. Non accettare endpoint arbitrari da config.

Rilevare continuation ripetute/cicliche e risposte JSON malformate. Verificare il risultato di Storage put/delete e non dichiarare import riuscito su false. Le regole host `CLAUDE.md` R3/R4/R14/R23/R30/R31/R32/R43/R44 si applicano all’adattatore comune: memoria bounded, errori espliciti, capability, tenancy, autorizzazione e servizi condivisi. Non aggiungere superfici amministrative prive dei Gate esistenti.

## Settings e strumenti operativi

Schema nativo con gruppi sync, contenitori, filtri, body/PII, allegati e reconciliation. Live discovery restituisce `list<string>` come richiesto dal base, con mapping provider ID/nome risolto nel pacchetto. Un errore non è una lista vuota. IDs immutabili prevalgono sui nomi per i settings; per Gmail trattare la pluralità di label come tale.

Diagnosticare connessione e poter mostrare un campione sintetico/read-only usando le stesse API. Un probe non salva fonti, non modifica cursori/config, non attiva triage, non accoda ingest e non chiama AI a pagamento. Se l’host non ha ancora una capability probe neutra, esporre health/discovery e indicare precisamente il limite; non instradare verso IMAP.

Metriche senza contenuti: run/account/installation, fase, tentativi, requests, bytes, discovered/imported/skipped/removed, cursor state, durata e error code. Non loggare body, indirizzi, subject, headers sensibili, token, authorization code o link con cursor segreto.

## Triage comune da conservare

1. Opt-in spento; profili revisionati con 1–10 caratteristiche attive `noul/choice/score`, soglie, testo e tag per opzione, assistente con draft che non salva/attiva automaticamente.
2. Una richiesta Jev combinata per email, contenuto come evidence non istruzioni; incertezza esplicita, nessun positivo inventato. Nessuna chiamata AI nei worker download.
3. Budget UTF-8 body-first dopo sanitizer; limiti correnti 32 KiB state, 1.500 caratteri/excerpt, 8 KiB excerpts, 5 MiB/file per estrazione locale; PDF prima pagina, DOCX/ODT e testo/HTML, nessun paid OCR implicito. Coverage visibile per esclusi/mancanti/unsupported.
4. Snapshot `.artifacts/email-triage` immutabili con hash, una sola carta per revisione al parse, search tags propagati ai chunks, versioning con ACL e manual tags preservati.
5. Evaluation dedup per contenuto/manifesto/profilo/modello/renderer; retry indicizzazione riusa decisione. Generazione indice manuale e guard contro lavori stale mantenute.
6. Queue dedicata, outbox recuperabile, heartbeat/readiness, errori distinti da risultati negativi, retry e disabilitazione che sospende AI/campagne ma lascia il download.
7. Campagne account/project/folder/date/state, preview conteggio e disponibilità con sample dichiarato, costi osservati/configurati o null, profilo/cutoff pinned, batch finiti, pause/resume/retry. Nessun refetch automatico né ricostruzione da chunk.
8. Log e audit privi di contenuti/credenziali; esecuzione, coverage, validità profilo e revisione indicizzata restano stati distinti.

## Ripartizione delle chat

La chat Gmail modifica il proprio repository e possiede le modifiche comuni dell’host in un worktree separato basato su `origin/develop`. Integra hook, triage, campagne, UI, registry e documentazione per Gmail e Microsoft Mail. Conserva compatibilità e identità delle email IMAP.

La chat Microsoft modifica esclusivamente il proprio repository. Legge l’host e il contratto comune, crea un finto listener/bridge in Testbench e consegna metadati e manifest compatibili. Non modifica Composer, lockfile, classi o frontend dell’host, pacchetti Gmail/IMAP/OneDrive o base. L’integrazione host completa viene collaudata dal proprietario Gmail dopo che il pacchetto Microsoft è disponibile.

Nessuna chat cambia il contratto v1 unilateralmente. Se emerge un’incompatibilità, documentare caso/test e soluzione proposta; continuare le attività indipendenti. Non inviare messaggi ad altre chat senza autorizzazione diretta dell’utente.

## Verifica e consegna

- Composer validate, PHPUnit package, Pint test e PHPStan; CI Laravel 12/13 sulle combinazioni PHP effettivamente supportate.
- Fake HTTP con `preventStrayRequests`, clock controllato, Storage fake, fake bridge e listener sincrono. Fixture sintetiche di MIME/JSON, nessun dato mailbox o token reale.
- Test OAuth/state/refresh-preserve-extra, tenant/account isolation, selection, full/incremental/resume, errore a metà pagina, invalid cursor, duplication, move/label update, omonimi, byte limits e tombstones scoped.
- Test contratto: prepared precede qualsiasi put/dispatch; envelope/manifest validati; body/attachment linkage; replay senza duplicazioni; errore listener interrompe checkpoint.
- Test host parametrizzati IMAP/Gmail/Microsoft: triage on/off, body gated e allegati indipendenti, uncertain/failed/retry/cost reuse, immutable source, campaign filters/cutoff/pause/restore, ACL/tags/versioning e index generation.
- Niente test automatici contro mailbox reali, attivazioni triage o decisioni pagate. Consegna distinta tra test fixture verdi e OAuth/live non ancora verificato.
- Commit locali atomici dopo ispezione del diff. Mai push, tag, PR o pubblicazioni remote. Non riscrivere commit. Non includere vendor, segreti, fixture private o modifiche preesistenti.
- In host usare branch feature derivato da origin/develop secondo `docs/GITFLOW.md`; non modificare direttamente develop/main né cambiare il checkout condiviso. Herd: mantenere dominio `askmydocsdev.test` e schema esistenti, non avviare artisan serve.
- Rapportare path repository/worktree, commit, comandi di test ed esito, integration status e sole limitazioni concrete. Un pacchetto scaffolding non è una consegna completata.
