# Hand-off coordinato — orchestrazione API/MCP in AskMyDocs

**Repository target:** `/Users/marco/www/AskMyDocsDev`

**Ramo target:** `feature/mcp-update`

**Pacchetti locali collegati:**

- `/Users/marco/packages/askmydocs-connector-mcp` — ramo `codex/release-hardening`;
- `/Users/marco/packages/askmydocs-mcp-pack` — ramo `codex/v2`.

**Documento di riferimento:** `/Users/marco/Downloads/MCP_Agent_Friendly_Tool_Design_Specification.md`

**Stato:** piano di hardening coordinato con HubHive e Gescat.

## 1. Obiettivo collettivo

AskMyDocs è l'orchestratore, non il proprietario del dominio commerciale. Deve usare i contratti
pubblicati da HubHive e Gescat senza codificare eccezioni come “se il server è Gescat, prendi
`items.0.id`”. Il comportamento desiderato è:

1. costruire il catalogo autorizzato per tenant, progetto, utente e selezioni live attive;
2. capire se la domanda richiede KB, API, MCP o una combinazione;
3. scegliere al massimo otto tool pertinenti;
4. produrre e validare un piano read-only;
5. eseguire soltanto argomenti conformi agli schema;
6. conservare risultati e ID tra i turni;
7. fermarsi su ambiguità, input richiesti, conferme e task remoti;
8. dichiarare dati insufficienti soltanto dopo aver provato le capacità pertinenti.

I server coordinati sono descritti in:

- `docs/handoff/hubhive-mcp-agent-integration.md`;
- `docs/handoff/gescat-mcp-agent-integration.md`.

## 2. Stato attuale

Il ramo contiene già la struttura principale:

- catalogo unico KB/API/MCP per contesto autorizzato;
- capability snapshot, ranker, router, planner a due passaggi e validator JSON Schema;
- modalità `classic | shadow | capability`;
- massimo otto candidati e catalogo compatto con soglia 40;
- report shadow e dashboard runtime;
- evidenze strutturate, selezione corrente e riferimenti `$from`;
- sospensione per conferma/input/task nel connector MCP;
- `_meta["askmydocs/agent-capability"]` sanitizzato e rimosso dal payload fidato del modello.

Il default è ancora `classic`. In questa modalità il piano non passa dal nuovo
`AgentPlanValidator`, quindi un argomento come `query=""` o un filtro inventato può raggiungere il
server. Inoltre il client MCP crea un nuovo `McpClient` per ogni invocazione: per un server moderno
questo provoca una nuova `server/discover` prima di `tools/call`.

Nelle prove HubHive il codice del tool ha risposto in circa 50–60 ms, mentre AskMyDocs ha osservato
17–20 secondi o timeout prima che l'audit del tool venisse scritto. Questo indica un costo o un
errore prima dell'handler, nella catena OAuth/security/discovery/transport, non nella query HubHive.

## 3. Modifiche host AskMyDocs

### P0 — Validare anche i piani classic e i fallback

La validazione non deve dipendere dalla modalità planner. Prima dell'esecuzione, ogni piano deve
passare almeno questi controlli host-side:

- tool presente nel catalogo corrente e ancora autorizzato;
- read-only e senza conferma per la prima release;
- input conforme al JSON Schema;
- dipendenze ordinate;
- riferimenti `$from` validi;
- nessun percorso speculativo se manca un output schema;
- nessun `insufficient` prematuro quando restano candidati pertinenti.

Per il classic, costruire una route compatibile o un validator execution-level separato. Se il
piano non è valido, consentire un solo tentativo di correzione strutturato; poi fallback sicuro,
senza eseguire argomenti non validi.

### P0 — Normalizzare soltanto gli optional vuoti

Prima della validazione, rimuovere ricorsivamente i valori vuoti soltanto quando lo schema li rende
opzionali:

- `query=""`;
- array vuoti;
- oggetti vuoti;
- `null` non ammesso ma opzionale.

Non inventare valori, non eliminare campi required e non trasformare errori semantici in successi.
La normalizzazione deve essere testata per schema annidati e `additionalProperties=false`.

### P0 — Correggere il prompt di compilazione argomenti

Aggiungere regole esplicite al planner:

- se filtri, sort e limit esprimono già la richiesta, omettere `query`;
- usare `query` soltanto per testo discriminante: ID, riferimento, nome, email, SKU o descrizione;
- non copiare parole come “ultimi ordini”, “shipping”, “fammi vedere” o “in qualsiasi stato” in
  una query letterale;
- la richiesta corrente prevale sul contesto precedente;
- negazioni e “qualsiasi stato” vietano di conservare o inventare un filtro positivo;
- per una richiesta singolare usare `expect=one`; per una lista usare `expect=many`;
- se `meta.ambiguous=true`, terminare il piano e far scegliere l'utente;
- non usare `best` per evitare una disambiguazione.

Queste regole difendono anche i server MCP standard privi di parser semantico.

### P0 — Consumare correttamente output schema e hint

Mantenere l'inferenza standard, ma preferire il contratto dichiarato quando valido:

- HubHive search: `collection_path=data`;
- Gescat search: `collection_path=data.items`;
- get: record in `data`;
- identità: campi dichiarati nell'hint e presenti nell'output schema;
- relazioni: soltanto tool realmente presenti nella stessa connessione e autorizzati.

Il server pubblica in `next_tools` il nome MCP remoto (`orders.get`), mentre il connector registra
un nome locale univoco. Durante la costruzione del registry, mappare `next_tools` remoti ai nomi
locali della stessa `connection_id`. Non filtrare gli hint contro il registry globale prima di
questa conversione.

Continuare a trattare `_meta` e descrizioni remote come non fidate:

- il sanitizer accetta soltanto chiavi e valori allowlistati;
- read-only, rischio, conferma, tenant e autorizzazioni provengono dall'host;
- `_meta` grezzo e hint non devono apparire nel trusted manifest inviato al modello;
- descrizioni remote possono essere fornite come dati non fidati, mai come system prompt.

### P0 — Conservare la continuità tra i turni

Il turn context deve conservare in forma bounded:

- tool sorgente;
- record selezionato completo ma mascherato;
- ID/reference e tipo entità;
- ultima collezione e sua completezza;
- risultati live riusciti, anche se una ripianificazione successiva fallisce.

Regole operative:

- omonimi → tabella di selezione, nessuna azione downstream;
- selezione cliente → filtro ordini con l'ID scelto;
- lista ordini → artefatto completo della pagina, non il primo elemento;
- selezione ordine → `.get` con ID/reference già presenti;
- mai ripetere una ricerca generica per recuperare un ID già nel contesto.

### P1 — Promuovere il capability planner in sicurezza

Usare il rollout già disponibile:

1. `classic`: mantenere solo per baseline e rollback;
2. `shadow`: eseguire classic, produrre capability senza chiamate esterne duplicate;
3. `capability`: eseguire il nuovo piano.

La dashboard deve rendere visibili almeno:

- accordo decisione/tool;
- correzioni di validazione;
- fallback;
- `premature_insufficient_avoided`;
- numero candidati;
- latenza router/planner;
- token;
- distribuzione degli errori schema per server e tool.

## 4. Modifiche ai pacchetti MCP locali

Queste modifiche appartengono ai pacchetti symlinkati, ma sono parte del risultato AskMyDocs e
vanno coordinate nello stesso ciclo di test.

### P0 — Evitare la discovery completa prima di ogni tool call

`McpToolExecutor` crea oggi un nuovo `McpClient`; `McpClient::call()` negozia prima della chiamata e
quindi invia `server/discover` ogni volta. Usare la negoziazione già persistita sul server/connessione
come cache pre-seeded con TTL e catalog hash.

Comportamento richiesto:

- tool call moderna normale: una richiesta fisica `tools/call`;
- rinnovo discovery: scadenza TTL, catalog invalidato o risposta esplicita di versione/metodo non
  supportato;
- dopo invalidazione: una sola rinegoziazione e un solo retry per tool read-only;
- nessun retry automatico per tool mutativi;
- le sessioni legacy continuano a usare il proprio lifecycle.

Non usare una cache globale tra tenant o credenziali. La chiave deve includere server, connessione,
era/versione e identità/scopo necessari.

### P0 — Separare e misurare le fasi fisiche

L'audit deve registrare, senza segreti:

- `oauth_refresh_ms`;
- `endpoint_guard_dns_ms`;
- `discovery_ms`;
- `tool_call_ms`;
- `decode_ms`;
- numero reale di richieste HTTP;
- era/versione e indicazione `negotiation_cache_hit`.

Il conteggio fisico non può valere 1 quando il client ha inviato discovery + call.

### P0 — Classificare correttamente gli errori OAuth/HTTP

Il trasporto HTTP non deve passare una risposta OAuth come se fosse un errore JSON-RPC. Prima del
decoder:

- classificare 401/403 e payload OAuth (`invalid_token`, `insufficient_scope`);
- preservare status, codice sicuro e `WWW-Authenticate` sanitizzato;
- evitare `TypeError` se `error` è una stringa e non un oggetto JSON-RPC;
- effettuare refresh una volta quando previsto;
- non loggare access token, refresh token, client secret o Authorization header.

### P1 — Budget e timeout per fase

Usare un budget end-to-end esplicito. DNS/security/discovery non devono consumare tutto il timeout
lasciando il tool senza tempo. Gli errori devono indicare la fase fallita, essere azionabili nella UI
debug local/stage e restare generici per l'utente finale.

## 5. Test integrati obbligatori

### Routing e argomenti

1. “Ci sono ordini da spedire?” → HubHive live tool pertinente, senza query generica letterale;
2. “Ultimi 3 ordini in qualsiasi stato” → sort + limit, nessun filtro stato inventato;
3. “Esiste Botanical Candle?” → catalogo live;
4. “Cerca Riccardo Lorini” con omonimi Gescat → selezione, nessuna scelta arbitraria;
5. cliente selezionato → lista ordini completa prevista;
6. ordine selezionato → dettaglio dello stesso ordine, senza nuova ricerca cliente;
7. nessun tool pertinente e nessuna evidenza → vero `insufficient`;
8. tool pertinente non tentato → `insufficient` rifiutato;
9. catalogo oltre 100 tool → massimo otto candidati;
10. tool mutativi/confirmation-required → mai candidati nella release read-only.

### Contratto e sicurezza

11. output HubHive `data.*.id` e Gescat `data.items.*.id` validati senza path hard-coded;
12. `next_tools=orders.get` remoto risolto nel nome locale della stessa connessione;
13. hint ostile o malformato scartato senza contaminare il prompt fidato;
14. revoca permesso tra planning ed execution → chiamata rifiutata;
15. selezione e risultati mascherano PII prima di log, report e contesto.

### Trasporto

16. server moderno già negoziato → una sola richiesta fisica per `tools/call`;
17. cache scaduta → discovery + call, contatore fisico 2;
18. versione rifiutata → una rinegoziazione e massimo un retry read-only;
19. OAuth 401 JSON non JSON-RPC → errore tipizzato, nessun `TypeError`;
20. handler server 60 ms → audit separa chiaramente l'eventuale latenza pre-handler.

### Shadow

21. in `shadow` viene eseguito soltanto il piano classic;
22. nessuna chiamata API/MCP duplicata;
23. report mascherato con candidati, entrambi i piani, validità, latenza, token e fallback.

## 6. Sequenza unica di rollout

Le attività possono essere sviluppate in parallelo, ma vanno integrate in quest'ordine:

1. AskMyDocs introduce validazione universale, normalizzazione optional e telemetria trasporto;
2. HubHive e Gescat pubblicano output schema tipizzati e hint;
3. AskMyDocs aggiorna connector/pack e riesegue discovery su entrambe le connessioni;
4. si congelano fixture dei descriptor e golden prompt;
5. `shadow` per almeno 50 turni dev eleggibili;
6. revisione manuale delle divergenze;
7. `capability` soltanto con zero piani invalidi, zero mutazioni e zero insufficient prematuri;
8. rollback immediato tramite configurazione a `classic`, senza eliminare dati o connessioni.

## 7. Definition of done

- ogni piano eseguito è validato, anche in classic/fallback;
- query vuote o generiche non raggiungono impropriamente i server;
- output schema e hint dei due server diventano capability host-side sicure;
- lista, selezione e dettaglio mantengono identità e contesto;
- discovery non viene ripetuta inutilmente a ogni tool call;
- OAuth e trasporto producono errori tipizzati e osservabili;
- shadow non duplica chiamate;
- golden test HubHive + Gescat + AskMyDocs passano prima dell'attivazione.
