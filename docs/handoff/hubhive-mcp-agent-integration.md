# Hand-off coordinato — HubHive MCP per agenti AskMyDocs

**Repository target:** `/Users/marco/www/HubHive`

**Ramo target:** `main`

**Baseline analizzata:** commit `cc982a3` (`feat: redesign MCP tools for agents`)

**Documento di riferimento:** `/Users/marco/Downloads/MCP_Agent_Friendly_Tool_Design_Specification.md`

**Stato:** proposta di implementazione coordinata, senza modifiche al codice HubHive in questo hand-off.

## 1. Obiettivo collettivo

HubHive non deve limitarsi a rispondere correttamente a una chiamata MCP isolata. Deve pubblicare un
contratto che permetta al planner di AskMyDocs di capire, prima della chiamata:

- quale tool usare per una richiesta operativa;
- quali argomenti sono validi;
- dove si trova la collezione nel risultato;
- quali campi identificano stabilmente un record;
- quale tool usare per il passaggio successivo, per esempio lista → dettaglio;
- quando un risultato è vuoto, parziale o ambiguo.

Questo documento è coordinato con:

- `docs/handoff/gescat-mcp-agent-integration.md` per mantenere la stessa semantica sui due server;
- `docs/handoff/askmydocs-live-data-orchestration.md` per il consumo sicuro del contratto lato client.

Il server resta MCP standard. Gli hint specifici per AskMyDocs sono facoltativi e soltanto
informativi: testo, descrizioni e `_meta` remoti non devono mai diventare istruzioni fidate del
modello.

## 2. Stato attuale

La nuova superficie è già ben orientata agli agenti:

- protocollo MCP `2026-07-28` e trasporto stateless;
- tool read-only con nomi stabili come `catalog.search`, `orders.search`, `orders.get`,
  `shipments.search` e `fulfillment.summarize`;
- input schema chiusi, preset, include, proiezioni, cursor e annotazioni di rischio;
- envelope uniforme `{ok, data, meta, error}`;
- limiti espliciti sulle relazioni e PII mascherata per scope.

Restano però tre problemi che oggi impediscono una pianificazione affidabile.

### 2.1 `query` è descritta come semantica, ma viene applicata come testo letterale

`match=smart` e `match=contains` finiscono entrambi in un `LIKE %testo%` sui campi tecnici
allowlistati. Una frase come `recent orders` o `shipping` non viene interpretata come ordinamento o
stato operativo: viene cercata letteralmente in ID, riferimenti, stato provider, SKU e nomi riga.

Nei test integrati si sono già verificati questi casi:

- richiesta “ordini da spedire”: AskMyDocs ha inviato `query="shipping"` insieme a un filtro
  strutturato e HubHive ha restituito zero record nonostante fossero presenti ordini pending;
- richiesta “ultimi tre ordini in qualsiasi stato”: è stata inviata una query generica e un filtro
  di stato non richiesto, producendo zero risultati;
- richiesta basata soltanto su ordinamento e limite: è stato generato `query=""`, incompatibile con
  `minLength: 1`.

AskMyDocs verrà corretto per non usare `query` come copia della frase dell'utente quando filtri,
sort e limit sono sufficienti. HubHive deve comunque rendere vera la promessa di `smart`, oppure
restringere esplicitamente il contratto: il server non deve dichiarare comprensione semantica se
esegue soltanto matching testuale.

### 2.2 L'output schema non descrive i record

L'attuale `outputSchema` dichiara `data` con schema vuoto. Il risultato runtime è valido, ma il
planner non può provare che `data.*.id` esista né può costruire in sicurezza una catena
`orders.search` → `orders.get`. È quindi costretto a ripianificare oppure rischia percorsi inventati.

### 2.3 Alcune intenzioni operative non hanno un filtro univoco

“Da spedire” può comprendere più stati di fulfillment, non un singolo valore. Il planner non deve
conoscere la tassonomia interna di HubHive né scegliere arbitrariamente `pending`.

## 3. Modifiche richieste

### P0 — Rendere coerenti ricerca libera e filtri strutturati

1. Conservare `query` opzionale e coprire esplicitamente il caso con soli `filters`, `sort`,
   `preset`, `expect` e `limit`; il client deve ometterla invece di inviare una stringa vuota.
2. Definire chiaramente le modalità:
   - `exact`: confronto esatto su ID e riferimenti documentati;
   - `contains`: ricerca letterale parziale sui campi documentati;
   - `smart`: interpreta almeno entità, riferimenti, termini temporali, stati umani e parole
     operative supportate, rimuovendo le stop word.
3. Se `smart` non può essere implementato in modo affidabile nella prima release, non deve essere
   un alias silenzioso di `contains`: rimuoverlo temporaneamente oppure descriverlo come ricerca
   testuale, lasciando la semantica ai filtri.
4. Filtri, ordinamento e limite devono prevalere sulle parole generiche della query. Una query non
   deve annullare un filtro strutturato valido.
5. Esporre in `meta.query_interpretation` ciò che il server ha realmente applicato: termini utili,
   filtri derivati, ordinamento derivato e termini ignorati. Non dichiarare `matched=true` senza
   una spiegazione verificabile.

### P0 — Aggiungere un filtro operativo per “da spedire”

Introdurre una rappresentazione di dominio che non obblighi il client a conoscere tutti gli stati.
La forma consigliata è additiva:

```json
{
  "filters": {
    "shipping_readiness": "ready_to_ship"
  }
}
```

Valori minimi consigliati: `ready_to_ship`, `in_progress`, `shipped`, `delivered`, `exception`.
HubHive decide internamente quali stati tecnici appartengono a ogni gruppo. In alternativa è
accettabile un filtro multi-valore tipizzato, ma il concetto “ready to ship” deve restare
interrogabile senza una seconda chiamata di discovery.

### P0 — Pubblicare output schema tipizzati per ogni tool

Non usare più `data: []` nel descriptor. Ogni tool deve dichiarare la shape effettiva:

- search: `data` è un array di record tipizzati;
- get: `data` è un record tipizzato;
- summarize: `data` è un oggetto di metriche tipizzate;
- `meta` dichiara almeno paginazione, completezza, ambiguità e request ID;
- `error` conserva l'envelope strutturato attuale.

Per `orders.search` e `orders.get`, dichiarare almeno `id`, `reference`, `display_name`, stati,
totale, valuta, data, customer, righe e spedizioni previste dai preset. Le proprietà condizionali
possono essere opzionali, ma non devono risultare inesistenti nello schema.

### P0 — Pubblicare hint semantici facoltativi

Ogni descriptor può aggiungere:

```json
{
  "_meta": {
    "askmydocs/agent-capability": {
      "entity": "order",
      "operation": "search",
      "intent_tags": ["orders", "customer", "shipping", "recent"],
      "collection_path": "data",
      "identity_fields": ["id", "reference"],
      "next_tools": ["orders.get"]
    }
  }
}
```

Regole di interoperabilità:

- `next_tools` usa i nomi MCP remoti stabili; AskMyDocs li mapperà ai nomi locali della stessa
  connessione;
- gli hint non possono cambiare read-only, autorizzazioni, rischio o conferma;
- non inserire prompt, istruzioni comportamentali o contenuti utente negli hint;
- un client MCP standard che ignora `_meta` deve continuare a funzionare senza differenze.

### P1 — Completare l'identità cliente negli ordini

Le richieste reali sono spesso “ordini di questa persona” o “di chi è questo ordine”. Il record
ordine deve poter includere un oggetto `customer` allowlistato con:

- ID stabile;
- riferimento leggibile;
- `display_name`;
- email mascherata o completa in base allo scope;
- eventuale nome azienda.

`orders.search` deve poter filtrare per ID cliente e, se autorizzato, cercare nome/email. La PII non
deve comparire in audit o schema di esempio.

### P1 — Non scegliere silenziosamente con `expect=best`

Oggi `best` può ridurre la lista al primo record ordinato anche senza un punteggio di confidenza.
Implementare una delle due opzioni:

1. ranking esplicito con `meta.match.score`, soglia minima e differenza minima dal secondo;
2. se il ranking affidabile non è disponibile, mantenere più candidati e impostare
   `meta.ambiguous=true`.

Mai trasformare “primo per data/ID” in “miglior corrispondenza” senza dichiararlo.

### P1 — Osservabilità end-to-end

- propagare un correlation/request ID dalla richiesta alla risposta e al tool-call audit;
- separare nei log discovery, autorizzazione, validazione, query e serializzazione;
- registrare durata handler e result count, senza argomenti sensibili;
- restituire rapidamente errori di schema e autenticazione, senza attendere il timeout del client.

## 4. Criteri di accettazione HubHive

I test automatici devono coprire almeno:

1. “ultimi 3 ordini in qualsiasi stato” → nessuna query testuale, nessun filtro stato, sort
   `ordered_at desc`, limit 3;
2. “ci sono ordini da spedire?” → filtro operativo server-side e almeno i record attesi;
3. “esiste il prodotto Botanical Candle?” → `catalog.search`, con identità stabile;
4. filtro + query discriminante → entrambi applicati senza contraddirsi;
5. `expect=one` con omonimi o più ordini plausibili → `ambiguous=true`, nessuna scelta arbitraria;
6. `expect=best` debole → ambiguità, non primo record silenzioso;
7. `orders.search` descriptor → output schema contenente il percorso `data.*.id`;
8. hint `orders.search.next_tools=orders.get` presente e privo di testo istruttivo;
9. un client che non dichiara supporto AskMyDocs riceve comunque testo e structured content MCP
   standard;
10. PII e credenziali assenti da descriptor, audit ed errori.

## 5. Dipendenze e ordine di rollout

1. HubHive pubblica output schema tipizzati, hint e semantica di ricerca corretta.
2. AskMyDocs esegue una nuova discovery della connessione HubHive e conserva descriptor e hash del
   catalogo.
3. AskMyDocs verifica i golden prompt in modalità `agent.planner.mode=shadow`.
4. Solo dopo zero piani invalidi e zero `insufficient` prematuri si passa a `capability`.

HubHive non deve aspettare l'attivazione del nuovo planner per correggere query e schema. AskMyDocs,
dal canto suo, non deve assumere che tutti i server abbiano già adottato questi hint: l'inferenza
standard e il fallback classico restano necessari.

## 6. Definition of done

- ricerca libera e filtri hanno semantica non contraddittoria;
- “da spedire” è rappresentabile con un filtro di dominio;
- ogni tool read-only ha input e output schema verificabili;
- gli hint sono facoltativi, validi e non istruttivi;
- lista → dettaglio è pianificabile senza inventare percorsi;
- golden test HubHive e test integrati AskMyDocs passano;
- nessun cambiamento amplia scope, PII o capacità mutative.
