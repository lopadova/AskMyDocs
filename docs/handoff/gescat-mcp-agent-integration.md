# Hand-off coordinato — Gescat MCP per agenti AskMyDocs

**Repository target:** `/Users/marco/www/gescat-laravel`

**Ramo obbligatorio:** `m-m/feature/mcp2-operatori-clienti`

**Baseline analizzata:** commit `a9c59289783` (`docs(mcp): document agent-friendly tool contracts`)

**Merge target previsto:** `dev`, da confermare nel normale flusso Gescat

**Documento di riferimento:** `/Users/marco/Downloads/MCP_Agent_Friendly_Tool_Design_Specification.md`

**Stato:** proposta di completamento del ramo MCP esistente.

## 1. Perché queste modifiche servono anche ad AskMyDocs

Gescat è uno dei server usati per verificare il comportamento reale del client e del planner
AskMyDocs. Le modifiche richieste qui non sono personalizzazioni grafiche o scorciatoie proprietarie:
servono a rendere esplicito, tramite contratti MCP standard, ciò che Gescat sa già fare.

AskMyDocs deve poter capire automaticamente che:

- `customers.search` produce una lista di clienti identificabili;
- se la lista contiene più omonimi deve fermarsi e chiedere una scelta;
- la scelta fornisce un ID riutilizzabile da `orders.search`;
- `orders.search` produce una collezione, non “il primo ordine”;
- un ordine selezionato può essere passato a `orders.get` per il dettaglio;
- `session.logout` è mutativo e non appartiene al rollout read-only.

Oggi la maggior parte di questa semantica esiste nell'implementazione e nelle descrizioni, ma non è
interamente verificabile dal descriptor. Il nuovo planner AskMyDocs rifiuta correttamente percorsi
di risultato non dichiarati: per questo Gescat deve tipizzare gli output e dichiarare le relazioni.

Questo hand-off va letto insieme a:

- `docs/handoff/hubhive-mcp-agent-integration.md`, che adotta lo stesso contratto su HubHive;
- `docs/handoff/askmydocs-live-data-orchestration.md`, che specifica come il client usa tali dati.

## 2. Stato attuale del ramo

Il ramo ha già compiuto il refactor principale:

- catalogo applicativo `3.0.0` con `customers.search/get`, `orders.search/get/aggregate`,
  spedizioni, resi, prodotti e categorie;
- query multi-termine, stop word, date relative, filtri tipizzati e preset;
- envelope `{ok, data, meta, error}` con cursor, completezza e ambiguità;
- `expect=one` segnala più candidati;
- preset `operational` include relazioni bounded;
- tool operatori e cliente separati e scoped;
- MCP Apps negoziate soltanto con `io.modelcontextprotocol/ui`, con fallback testuale e
  `structuredContent` invariato;
- annotazioni read-only, Gate di dominio e PII allowlistata.

Questa base non va riscritta. Le modifiche seguenti completano il contratto per il consumo da parte
di un planner strutturato.

## 3. Modifiche richieste

### P0 — Sostituire l'output schema generico con schema per tool

`AbstractMcpTool::outputSchema()` dichiara oggi `data` come semplice `object`. La shape runtime è
invece precisa:

- search → `data.items`;
- get → record in `data`;
- aggregate → `data.metrics`;
- error → `data=null`.

Creare helper di schema riusabili, ma far sì che ogni tool pubblichi il proprio record type. Esempio
minimo per `customers.search`:

```json
{
  "data": {
    "type": "object",
    "properties": {
      "items": {
        "type": "array",
        "items": {
          "type": "object",
          "properties": {
            "id": {"type": "string"},
            "reference": {"type": "string"},
            "display_name": {"type": "string"},
            "email": {"type": ["string", "null"]}
          },
          "required": ["id", "display_name"]
        }
      }
    },
    "required": ["items"]
  }
}
```

Per `orders.search`, dichiarare almeno `id`, `reference`, `display_name`, status, date, total e
customer. Per `orders.get`, includere le shape opzionali di customer, items/products e shipments.
Gli include soggetti a Gate restano opzionali, mentre `meta.permission_limited` spiega l'assenza.

### P0 — Aggiungere hint semantici facoltativi

Usare `_meta["askmydocs/agent-capability"]` sui descriptor, senza modificare il comportamento MCP
per gli altri client.

Esempio `customers.search`:

```json
{
  "entity": "customer",
  "operation": "search",
  "intent_tags": ["customers", "people", "email", "orders"],
  "collection_path": "data.items",
  "identity_fields": ["id", "reference", "email"],
  "next_tools": ["customers.get", "orders.search"]
}
```

Esempio `orders.search`:

```json
{
  "entity": "order",
  "operation": "search",
  "intent_tags": ["orders", "customer", "shipping", "recent"],
  "collection_path": "data.items",
  "identity_fields": ["id", "reference"],
  "next_tools": ["orders.get", "shipments.search", "orders.aggregate"]
}
```

I nomi in `next_tools` sono i nomi MCP remoti. AskMyDocs è responsabile della loro conversione nei
nomi locali generati per la connessione. Gli hint non possono rendere read-only `session.logout`,
saltare Gate o cambiare audience.

### P0 — Rendere inequivoco il flusso omonimo → scelta → ordini

Con una richiesta singolare come “trova Riccardo Lorini”, `expect=one` deve:

- restituire i candidati bounded in `data.items`;
- impostare `meta.ambiguous=true` se i candidati plausibili sono più di uno;
- includere `meta.disambiguation_fields` utili e non sensibili;
- non scegliere il primo record e non caricare ordini di un cliente arbitrario.

Dopo la scelta, `orders.search` deve accettare direttamente
`filters.customer.ids=[<id selezionato>]`, con `expect=many`. Il risultato deve contenere tutti i
record della pagina prevista e `meta.total_count/has_more/next_cursor`; non deve essere ridotto
all'ultimo ordine salvo richiesta esplicita `limit=1`.

### P0 — Rafforzare la semantica di `query`

Gescat gestisce già stop word, date relative e termini attraversando cliente, stato, righe e
tracking. Aggiungere test che impediscano regressioni:

- una query composta non deve richiedere che parole puramente operative come “passami”, “lista” o
  “dettaglio” compaiano nel database;
- quando il client fornisce filtri strutturati, non è obbligatorio ripetere la frase in `query`;
- `query` vuota o assente deve essere valida quando esistono filtri/sort/limit;
- negazioni e “qualsiasi stato” non devono trasformarsi in un filtro positivo inventato;
- `filters.status.query="da spedire"` deve risolvere la terminologia pubblica/configurata oppure
  restituire un errore azionabile, non una lista vuota indistinguibile.

### P1 — Definire correttamente `expect=best`

Nel contratto attuale `best` forza `limit=1`, ma non esiste necessariamente un ranking con soglia
di confidenza. Il primo record ordinato non è automaticamente il miglior match.

Implementare ranking e metadati di score oppure trattare un match non univoco come ambiguo. Se non
si può dimostrare il candidato migliore, restituire più candidati e far scegliere l'utente.

### P1 — Rendere le relazioni navigabili senza conoscere il database

Uniformare gli identificatori delle relazioni:

- customer: `id`, `reference`, `display_name`;
- order: `id`, `reference`, `display_name`;
- shipment/return/product: stessa triade quando applicabile.

I record annidati devono usare gli stessi ID accettati dai relativi tool `.get`. In questo modo una
selezione visuale può essere reinviata al planner senza traduzioni specifiche per Gescat.

### P1 — Conservare il fallback MCP Apps

Non cambiare il comportamento già corretto:

- con capability UI valida, esporre `ui://gescat/...` e `_meta.ui.resourceUri`;
- senza capability UI, restituire gli stessi dati in testo e `structuredContent`;
- nessuna UI resource deve essere necessaria per completare la richiesta;
- l'HTML non deve contenere token, dati utente hard-coded o istruzioni per il modello.

## 4. Test golden obbligatori

1. `customers.search(query="Riccardo Lorini", expect="one")` con 15 omonimi → lista bounded,
   `ambiguous=true`, nessuna selezione automatica;
2. selezione cliente → `orders.search(filters.customer.ids=[id], expect="many")` → collezione e
   paginazione, non solo l'ultimo record;
3. selezione ordine → `orders.get(reference=<id o reference>)` → dettaglio dello stesso ordine,
   senza nuova ricerca cliente;
4. “ultimi 5 ordini del cliente” → sort data desc, limit 5 e nessuna query generica obbligatoria;
5. “quanti ordini e per quale totale” → `orders.aggregate`, non download e somma lato agente;
6. output schema search contiene `data.items.*.id` e get contiene `data.id`;
7. hint `next_tools` usa nomi remoti esistenti nel catalogo corrente;
8. descrizione o `_meta` contenente testo ostile non modifica autorizzazioni o comportamento;
9. `session.logout` resta distruttivo, richiede conferma e non è candidato nel rollout read-only;
10. test con e senza `io.modelcontextprotocol/ui` producono gli stessi dati strutturati.

## 5. Compatibilità e sicurezza

- non serializzare modelli Eloquent o resource REST direttamente;
- mantenere audience operatore/cliente e Gate esistenti;
- niente ID cliente nel server cliente: lo scope deriva sempre dal token;
- gli hint sono advisory e non contengono descrizioni libere;
- errori e audit non includono password, Bearer token o PII non necessaria;
- le modifiche a output schema e `_meta` sono additive per i client che ignorano tali campi.

## 6. Ordine di integrazione con AskMyDocs

1. Implementare e testare su `m-m/feature/mcp2-operatori-clienti`.
2. Confrontare `tools/list` prima/dopo e verificare che nomi e input schema non cambino
   accidentalmente.
3. Eseguire la nuova discovery della connessione Gescat in AskMyDocs.
4. Verificare che il catalogo AskMyDocs riporti `collection_path=data.items`, identità e relazioni.
5. Eseguire i golden prompt prima in `shadow`, poi in `capability`.
6. Solo dopo i test integrati proporre il merge del ramo verso `dev`.

## 7. Definition of done

- output schema specifici e verificabili per tutti i tool commerciali;
- hint semantici validi e facoltativi;
- omonimi mai risolti arbitrariamente;
- liste mai ridotte al primo/ultimo elemento senza richiesta esplicita;
- selezione → dettaglio conserva lo stesso ID;
- MCP Apps e fallback standard restano equivalenti nei dati;
- suite MCP Gescat e golden test AskMyDocs verdi.
