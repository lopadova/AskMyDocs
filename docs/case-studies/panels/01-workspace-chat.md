# Workspace — Chat

Il gruppo **Workspace** della sidebar contiene un'unica voce, **Chat**: è la pagina
con cui ogni utente interroga la knowledge base in linguaggio naturale e riceve
risposte ancorate ai documenti (con citazioni). È anche il pannello più importante
per il collaudo dell'**isolamento dei dati**: una domanda fatta su un'azienda non
deve mai ricevere in risposta i documenti di un'altra.

I tre dataset fittizi usati negli scenari di test sono:

| project key             | dominio                                                         |
| ----------------------- | -------------------------------------------------------------- |
| `rotta-logistics`       | logistica / spedizioni                                         |
| `prometeo-antincendio`  | consulenza normativa antincendio / vigili del fuoco           |
| `passolibero-calzature` | vendita scarpe                                                  |

---

## Chat (conversazioni salvate)

### Percorso

- **Route SPA**: `/app/chat` (nuova chat) e `/app/chat/$conversationId` (chat
  esistente, l'id della conversazione è nell'URL ed è la fonte di verità).
- **Gruppo sidebar**: Workspace → Chat (icona `Chat`, definita in
  `frontend/src/components/shell/nav-config.ts`).
- Il redirect di default: `/app` e `/app/chat` index puntano qui; aprire `/app`
  reindirizza a `/app/chat`.

### Ruoli

Nessun `RequireRole` avvolge la route in `frontend/src/routes/index.tsx`: la Chat
è accessibile a **qualsiasi utente autenticato** (super-admin, admin, dpo, editor,
viewer). L'unico vincolo è `RequireAuth` sul layout `/app`. Differenza per i ruoli:
solo **admin** e **super-admin** vedono i chip di citazione diventare cliccabili
(aprono il documento KB su `/app/admin/kb`, che è dietro RBAC); per viewer/editor il
chip resta puramente informativo per non finire su un 403.

### A cosa serve

È l'interfaccia conversazionale del sistema RAG: l'utente pone una domanda, il
backend recupera i chunk più pertinenti dalla KB del progetto attivo (ricerca
ibrida vettoriale + full-text + reranker), compone un prompt ancorato e fa
rispondere il modello. Ogni risposta cita le fonti da cui è stata estratta.
Le conversazioni sono persistite per-utente (cronologia nella colonna sinistra) e
possono essere rinominate, biforcate (branch), rigenerate e modificate a posteriori.
Quando il contesto recuperato è insufficiente, la chat **rifiuta deliberatamente**
di rispondere invece di inventare.

### Cosa vedi nella pagina

Layout a colonne (componente radice `ChatView`, `frontend/src/features/chat/ChatView.tsx`,
`data-testid="chat-view"`):

- **Colonna sinistra — lista conversazioni** (`ConversationList`): elenco delle chat
  salvate dell'utente, pulsante per nuova chat e una voce per avviare una chat
  anonima (non persistita).
- **Header della conversazione** (`data-testid="chat-header"`): titolo (auto-generato
  dal transcript al primo turno, oppure `Conversation #<id>` / `New chat`), sottotitolo
  mono con l'etichetta del progetto e il modello (`claude-sonnet-4.5`), e un pulsante
  azioni (`chat-header-more`).
- **Thread dei messaggi** (`MessageThread`, `data-testid="chat-thread"` con
  `data-state ∈ idle|loading|ready|empty|error`): bolle utente/assistente con
  streaming token-per-token. Stato vuoto (`chat-thread-empty`) con prompt suggeriti;
  ogni bolla assistente porta citazioni, badge di confidenza e — se attivo — il
  pannello controfattuale. Le bolle supportano rigenera, branch ed edit del messaggio
  utente.
- **Citazioni / fonti**: ogni risposta espone le fonti raggruppate per documento, con
  marcatore di origine (`primary` = contesto primario, `related` = espansione grafo,
  `rejected` = approccio scartato). Sui ruoli admin il chip è cliccabile e apre il
  documento KB.
- **Suggested follow-ups** (`SuggestedFollowups`): pillole con 3 domande di follow-up
  generate dopo che un turno si è concluso.
- **Composer** (`Composer`, form `data-testid="chat-composer"`): la box della domanda
  (`chat-composer-input`, placeholder "Ask anything grounded in your knowledge base…"),
  pulsante invio (`chat-composer-send`, anche Invio; Shift+Invio per andare a capo) che
  durante lo streaming si trasforma in **Stop** (`chat-composer-stop`). Sotto il
  composer compaiono gli errori inline: `message-error` (campo richiesto vuoto) e
  `chat-composer-error` (errore dal provider/trasporto).
- **Barra filtri** (`FilterBar`, `data-testid="chat-filter-bar"`): sopra la textarea,
  sempre visibile. Pulsante "+ Filter" (`chat-filter-bar-add`) che apre la popover di
  selezione, un dropdown di **preset filtri** salvati per-utente, un badge con il
  numero di dimensioni attive e un "Clear all" (`chat-filter-bar-clear`). Ogni filtro
  attivo è un chip rimovibile. Dimensioni filtrabili: **project_keys** (progetti),
  **tag_slugs**, **source_types**, **canonical_types**, **connector_types**, **doc_ids**
  (via @mention nel testo), **folder_globs**, **languages**, **date_from/date_to**.
  Nel composer c'è anche un selettore "Scope" (`chat-collection-picker`) per limitare a
  una **collection**, e i chip di contesto a sola lettura (etichetta progetto,
  "canonical only", modello).

> Nota sul progetto attivo: il `ProjectSwitcher` nel topbar (vedi sotto) e il suo
> stato vivono in `AppShell`; oggi `ChatView` crea la conversazione usando il primo
> progetto disponibile e la conversazione resta legata al proprio `project_key` (la
> colonna `conversations.project_key`, impostata alla creazione). Lo scoping per-turno
> che l'utente controlla direttamente è quello della **barra filtri**
> (`filters.project_keys`) — è la leva da usare negli scenari di isolamento qui sotto,
> perché quando presente ha **sempre la precedenza** sul `project_key` legacy
> (vedi `KbChatRequest::toFilters()`).

### Il ProjectSwitcher nel topbar

`Topbar` (`frontend/src/components/shell/Topbar.tsx`) monta il `ProjectSwitcher`
(`ProjectSwitcher.tsx`): un menu a tendina single-select (pattern ARIA
`menu` + `menuitemradio`) con l'elenco dei progetti a cui l'utente ha accesso
(dalle membership reali in `auth-store`; in assenza, il fallback `PROJECTS` di
`lib/seed.ts`). Ogni voce mostra pallino colore, label e conteggio documenti, con
un check sul progetto attivo. Esc chiude e riporta il focus al trigger. Il topbar
mostra inoltre il badge "All systems operational", la campanella notifiche, il
toggle tema e il pannello "Tweaks".

### Dati / endpoint

- **POST `/api/kb/chat`** — turno stateless (usato dalla chat anonima e dal percorso
  base). Controller `app/Http/Controllers/Api/KbChatController.php`, validazione
  `app/Http/Requests/Api/KbChatRequest.php`. Payload: `{ question, project_key?, filters? }`.
  In `filters` le dimensioni accettate sono `project_keys`, `tag_slugs`, `source_types`
  (enum `SourceType`), `canonical_types` (enum `CanonicalType`), `connector_types`,
  `doc_ids`, `collection_id`, `folder_globs`, `date_from`/`date_to`, `languages`. Quando
  `filters.project_keys` è presente vince sul `project_key` legacy.
- **Conversazioni persistite** (percorso usato dalla SPA con cronologia):
  - `GET /conversations`, `POST /conversations` (`{ project_key }`),
    `PATCH /conversations/{id}`, `DELETE /conversations/{id}`,
    `POST /conversations/{id}/generate-title`.
  - `GET /conversations/{id}/messages`, `POST /conversations/{id}/messages`
    (`{ content, filters? }`) — controller `app/Http/Controllers/Api/MessageController.php`.
    Lo scope di progetto qui è `conversation->project_key`; i `filters` opzionali
    affinano ulteriormente il recupero.
  - `POST /conversations/{id}/messages/{messageId}/feedback`,
    `.../branch-from-message/{messageId}`, `DELETE .../messages-from/{messageId}`,
    `POST .../suggested-followups`.
- **GET `/api/kb/collections`** — opzioni del selettore "Scope".
- **GET `/api/kb/chat/anonymous-config`** — probe `{ enabled }` per la chat anonima.
- Tutti i client FE stanno in `frontend/src/features/chat/chat.api.ts`.

Sotto il cofano, ogni canale (stateless, conversazione, streaming) converge su
`ChatRetrievalService::retrieve()` (`app/Services/Kb/Chat/ChatRetrievalService.php`),
che esegue un'unica ricerca `searchWithContext()` **scopata per `projectKey` e per
tenant** e applica un **gate di rifiuto deterministico** (`shouldRefuse()` →
`RetrievalGrounding`): se troppo pochi chunk superano la soglia di similarità, il
controller **non chiama nemmeno il modello** e ritorna un payload di rifiuto con
`refusal_reason = "no_relevant_context"`.

### Il comportamento di rifiuto (anti-allucinazione)

Esistono due rifiuti, entrambi resi nel thread con un avviso dedicato
(`RefusalNotice`), `confidence = 0` e citazioni vuote:

1. **`no_relevant_context`** — nessun chunk supera la soglia di grounding. Nessuna
   chiamata al modello (latenza solo di retrieval, `refused_early = true`).
2. **`llm_self_refusal`** — il retrieval trova qualcosa ma il modello dichiara il
   contesto insufficiente emettendo il sentinello `__NO_GROUNDED_ANSWER__`; il
   controller lo converte in rifiuto.

In entrambi i casi la risposta NON contiene dati di un altro progetto: il recupero è
già scopato al progetto/tenant a monte del gate.

---

## Come testarlo con i 3 dataset

Prerequisito: aver ingestito i documenti dei tre progetti (`rotta-logistics`,
`prometeo-antincendio`, `passolibero-calzature`) con contenuti chiaramente disgiunti
(es. tempi di transito e vettori per la logistica; distanze minime degli estintori e
adempimenti CPI per l'antincendio; taglie/cambi/resi per le calzature).

### A) Risposta corretta dentro al progetto giusto

1. Apri `/app/chat`, avvia una nuova chat.
2. Nella **barra filtri** apri "+ Filter" e seleziona il progetto
   **`rotta-logistics`** (chip `project`).
3. Chiedi una domanda di logistica, es. *"Qual è il tempo di transito di una
   spedizione standard?"* (il dato — 2–3 giorni lavorativi — vive in
   `servizi-spedizione.md`).
4. **Atteso**: risposta ancorata con citazioni che puntano SOLO a documenti
   `rotta-logistics` (origine `primary`); badge di confidenza > 0. Nessuna fonte di
   altri progetti nella lista citazioni.

### B) Isolamento — il cuore del collaudo (azienda A, domanda solo di B)

Questo verifica che "i documenti non si mischiano".

1. In una nuova chat, imposta il filtro progetto su **`passolibero-calzature`**
   (azienda A — scarpe).
2. Fai una domanda la cui risposta esiste **solo** nei documenti di un'altra azienda,
   es. *"Qual è la distanza minima a norma tra due estintori in un capannone?"*
   (questa informazione vive solo in **`prometeo-antincendio`**, azienda B).
3. **Atteso**: la chat **deve rifiutare** con `refusal_reason = "no_relevant_context"`
   (avviso `RefusalNotice`, confidenza 0, nessuna citazione). NON deve rispondere con
   la distanza minima presa dai documenti antincendio di B. Se il modello recupera
   qualcosa ma lo giudica fuori contesto, il rifiuto sarà `llm_self_refusal`: anche
   questo è un PASS.
4. Ripeti incrociando le coppie (filtro `rotta-logistics` + domanda su scarpe; filtro
   `prometeo-antincendio` + domanda su tempi di transito). Ogni volta l'esito atteso è
   un rifiuto, mai una risposta con i dati dell'altra azienda.

**FAIL da segnalare**: se selezionando l'azienda A la chat risponde nel merito con
informazioni che esistono solo in B, l'isolamento è rotto — è il difetto più grave
da intercettare in questo pannello.

### C) Controprova positiva dell'isolamento

1. Stessa domanda del punto B2 (distanza minima estintori) ma con il filtro progetto
   impostato su **`prometeo-antincendio`** (l'azienda che possiede davvero il
   documento).
2. **Atteso**: ora la chat risponde correttamente con citazioni verso documenti
   `prometeo-antincendio`. Confronto diretto con il punto B: stessa domanda, esito
   opposto a seconda del progetto selezionato → dimostra che il filtro progetto è la
   frontiera di scoping reale.

### D) Verifica tramite ProjectSwitcher e cronologia

1. Apri il `ProjectSwitcher` nel topbar e conferma che siano elencati solo i progetti
   a cui l'utente ha accesso.
2. Crea una conversazione per ciascun dataset; verifica che la cronologia (colonna
   sinistra) mostri le chat dell'utente e che l'header riporti l'etichetta del
   progetto corretto.

---

## Appendice — Chat anonima (`/app/chat/anonymous`)

### Percorso

- **Route SPA**: `/app/chat/anonymous` (componente `AnonymousChatView`).
- **Gruppo sidebar**: raggiungibile dal Workspace → Chat (voce "nuova chat anonima"
  nella `ConversationList`); non ha una voce di sidebar propria.

### Ruoli

Come la chat normale: **qualsiasi utente autenticato** (solo `RequireAuth`, nessun
`RequireRole`). La feature è inoltre gated da configurazione
(`KB_ANONYMOUS_CHAT_ENABLED`).

### A cosa serve

Turno di chat **autenticato ma non persistito**: posta su `POST /api/kb/chat` con
`anonymous: true`. Il backend forza il mascheramento PII della domanda prima del
retrieval/LLM/log, non scrive alcuna conversazione/messaggio e logga solo il minimo. I
turni vivono solo in memoria del componente: un refresh azzera il thread (è il senso
dell'"anonimo").

### Cosa vedi nella pagina

Vista autonoma (`data-testid="anonymous-chat-view"` con `data-state`): header con
pulsante "Back" verso `/app/chat`, banner informativo, thread (`role="log"`,
`anonymous-chat-thread`) con stato vuoto (`anonymous-chat-empty`) e textarea
(`anonymous-chat-input`) + Send (`anonymous-chat-send`). Tre landing terminali:
caricamento (`anonymous-chat-loading`), errore di probe (`anonymous-chat-error`) e
**feature disabilitata** (`anonymous-chat-disabled`, mostra come abilitarla con
`KB_ANONYMOUS_CHAT_ENABLED=true`). Ogni turno mostra domanda, "Thinking…", risposta in
markdown con citazioni, oppure il `RefusalNotice` se rifiutato.

### Dati / endpoint

- **GET `/api/kb/chat/anonymous-config`** → `{ enabled }`.
- **POST `/api/kb/chat`** con `{ question, anonymous: true, filters? }`.
- Client: `anonymousChatApi` in `frontend/src/features/chat/chat.api.ts`.

### Come testarlo con i 3 dataset

1. **OFF (default)**: con `KB_ANONYMOUS_CHAT_ENABLED` non impostato, apri
   `/app/chat/anonymous`. **Atteso**: landing `anonymous-chat-disabled`, nessuna
   textarea (degrado pulito, niente 500). Un POST diretto a `/api/kb/chat` con
   `anonymous: true` deve tornare **422**.
2. **ON**: con la feature attiva, fai una domanda di logistica scopando con
   `filters.project_keys = ["rotta-logistics"]`. **Atteso**: risposta ancorata, niente
   persistenza (ricaricando la pagina il thread è vuoto).
3. **Isolamento**: con `filters.project_keys = ["passolibero-calzature"]` chiedi la
   distanza minima tra estintori (solo `prometeo-antincendio`). **Atteso**: rifiuto
   `no_relevant_context`, mai i dati dell'azienda B. La chat anonima non aggiunge alcun
   `project_key` proprio: l'unico scoping è quello dei `filters`, quindi è l'unica leva
   da impostare nello scenario.
