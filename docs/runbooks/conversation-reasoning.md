# Focus, memoria delle evidenze e validazione selettiva

## Contratto

Ogni nuovo messaggio ha una sola interpretazione strict, salvata sul messaggio utente
(`metadata.reasoning`) o sullo step widget (`args_json.reasoning`). I retry riusano
lo snapshot. Non si reinterpreta una continuazione di tool.

### Azione conversazionale interpretata dal modello

Ogni nuova interpretazione richiede anche `action`, enum chiuso:
`research`, `refine`, `read_source`, `provenance`, `recap`, `recheck`, `clarify`.
Il preprocessore decide l'azione usando messaggio e contesto; PHP non cerca parole
come "leggere", "show" o "ok" per decidere cosa intende l'utente. Le offerte generate
dal server sono memorizzate in `grounding.offered_actions` e passate come atti del
dialogo, senza reinserire il testo fattuale delle vecchie risposte. Con più offerte o
bersagli plausibili una semplice accettazione richiede chiarimento.

L'azione è salvata in `reasoning.understanding.action`, esportata nel debug e riusata
nei retry e nei rami di ricerca. Pianificatore, sintetizzatore, KB sincrona/streaming
e widget ricevono lo stesso contratto. La voce eredita l'interpretazione dell'agente.
Un output privo di azione, non valido o incoerente con transizione/chiarimento degrada
alla domanda esplicita: nessuna regex tenta una seconda interpretazione. I vecchi
snapshot restano leggibili: solo le transizioni tipizzate `recap` e `provenance`
vengono migrate; non si indovina una lettura dal vecchio campo `intent`.

`read_source` abilita la lettura deterministica nell'agente solo quando è disponibile
una singola fonte adatta nel pacchetto di evidenze e passano i controlli esistenti.
Non autorizza fonti e non risolve da solo riferimenti MCP verso email.
Restano deterministici schema, limiti, menzioni letterali, identità, hash e permessi;
le regex tecniche su formati/identificativi non interpretano il linguaggio dell'utente.

### Recupero delle email dopo un risultato MCP/API

La ricerca ricorsiva affianca alla similarità una ricerca locale per identificativi
completi già estratti dal preprocessore o risolti dalla memoria autorizzata. Questo
permette di passare da un tracking noto a un'email indicizzata che lo contiene, anche
se la query semantica non produce candidati. Funziona anche con codici senza cifre;
non usa il testo di una vecchia risposta come fonte e non inventa punteggi di similarità.
Il lookup è limitato a 12 identificativi e 64 chunk candidati; una lista vuota o
incompleta non dimostra l'assenza del dato. I candidati passano ancora da filtri,
`KbSourceReader` (tenant, progetto, attore, ACL) e valutazione LLM di pertinenza.

Una ricerca vuota o un suggerimento già eseguito non esauriscono automaticamente il
turno: si provano le query alternative della stessa interpretazione entro la profondità
configurata (massimo 5 round). Non viene effettuato un secondo preprocessing. Nell'agente
un risultato KB vuoto lascia proseguire la pianificazione sugli strumenti autorizzati;
profilo obbligatorio mancante e focus ambiguo restano blocchi anche dopo una ripresa.

Il debug del run contiene `result_json.knowledge_investigation`, con query tentate,
numero di candidati semantici/esatti, fonti leggibili, documenti selezionati ed esito di
ciascun tentativo. Assenza di evidenze, mancata pertinenza ed errore tecnico sono distinti;
nessuno di questi esiti autorizza l'affermazione «l'email non esiste».

Questa modifica non richiede migrazioni o build frontend. Riavviare i worker con
`php artisan queue:restart` dopo il deploy; testare con un nuovo messaggio, perché
un retry del turno precedente deve conservare la sua interpretazione originale.

`conversations.reasoning_state` e `widget_sessions.reasoning_state` sono JSON scritti
esclusivamente dal server, non mass-assignable e nascosti nelle normali risorse Eloquent.
La memoria è della singola conversazione: nessun riuso automatico tra chat.

Il flusso principale è:

```text
Messaggio → prepare / interpretazione strict → focus e sottodomande
    → rilettura fonti autorizzate / chiamate live → evidenze normalizzate
    → proposta di fatti atomici
    → controlli PHP per fatto → una richiesta JEV con massimo 10 choice
    → fatti approvati / campi strutturati di fallback / esclusioni
    → composizione senza riscrittura libera → salvataggio → finish / memoria
```

KB sincrona, streaming e widget condividono focus, riferimenti e gestione del turno;
la voce eredita il percorso agente. La valutazione JEV resta nell'agente, non viene
aggiunta alle altre chat KB. Il recap narrativo storico resta memorizzato ma, con il
nuovo percorso attivo, non viene reiniettato come fonte nel prompt di risposta.

## Componenti

- `ConversationReasoning`: prepara il turno, riconvalida la memoria, finalizza atomicamente
  lo stato. Lock per interpretazione dello stesso turno; lock DB durante gli aggiornamenti.
  Nessuna transazione rimane aperta durante una chiamata LLM.
- `QuestionUnderstanding` / `FocusContract`: focus, sottodomande, transizione,
  chiarimento, menzioni letterali e riferimenti contestuali separati. Un riferimento
  contestuale deve già essere noto al server; non può creare relazioni aziendali.
- `ResearchFanout`: esegue i task in processi PHP isolati, con concorrenza e timeout
  limitati. Un processo fallito o scaduto non fa perdere i risultati degli altri.
- `AgentResearchCoordinator`: un run interno per task, con strumenti, checkpoint,
  fonti e budget distinti. Solo il run padre salva la risposta e aggiorna la memoria.
- `KbSourceReader`: rilettura con tenant, progetto, attore, ACL e filtri correnti.
- `AgentEvidenceAccess`: ricontrolla checkpoint e osservazioni prima della pianificazione
  e della sintesi. Fonte revocata/modificata o connettore non più disponibile non entra
  nel contesto. I payload storici delle azioni non sono una seconda fonte nascosta.
- `EvidenceProjector`: proiezione dei record senza duplicare wrapper e testo MCP.
  Conserva hash esterno e percorso del record. Il match usa l'identità del record,
  non il testo della query né un codice presente in `emailEvidence`.
- `FocusedClaimEvaluator`: controlli indipendenti e allocazione round-robin delle dieci
  decisioni tra sottodomande. Le citazioni sono associate dal server al passo originale.
- `DecisionOnce`: marcatore persistente di invio su `agent_runs.result_json.focused_decision`.
  Un retry con lo stesso stato riusa il risultato; un timeout o uno stato diverso non
  provoca un secondo invio automatico. È una garanzia *al massimo una chiamata*, non
  di consegna esattamente una volta in presenza di crash.

Lo stato distingue menzioni dell'utente, riferimenti trovati, fatti verificati e testi
effettivamente comunicati. Le relazioni strutturate conservano l'esecuzione che le
attesta; servono per navigare verso una nuova lettura, non per attestare lo stato live.
Il testo libero delle chat KB viene marcato `reported_text / not_claim_validated`:
è solo un ausilio antiripetizione, mai un'evidenza. I suggerimenti testuali di memoria
sono esposti solo se le fonti associate sono ancora autorizzate e il passo è invariato.

Una risposta tardiva può aggiungere evidenze ma non sostituisce il focus più recente.
La finalizzazione è idempotente per turno, anche dopo l'espulsione dal buffer in memoria,
grazie al marcatore sul messaggio salvato. I preamboli dei tool widget non finalizzano il turno.

Limiti iniziali: 100 riferimenti, 64 KiB, 100 elementi nelle liste di fatti/entità/turni,
20 sottodomande. Si eliminano prima riferimenti vecchi fuori dal focus. Non si copiano
interi payload nella memoria; restano nei run originali. Eliminando la conversazione o
sessione si elimina automaticamente anche il relativo campo di memoria.

## Dati live, incertezza e rollback

Per una nuova domanda i precedenti valori MCP/API non sono evidenza corrente: il planner
riceve riferimenti per richiamare gli strumenti. Solo `transition=recap`, derivata da una
richiesta esplicita, può recuperare snapshot autorizzati; la data di acquisizione resta
visibile e il prompt impone di presentarli come storici. Un errore di aggiornamento
non ripristina silenziosamente il valore precedente.

Le cinque categorie JEV sono `supported_relevant`, `supported_off_topic`, `unsupported`,
`conflicting`, `insufficient_context`. Solo la prima, con probabilità almeno 0,80,
ammette la resa narrativa. Un no affidabile non può essere aggirato. La distribuzione
deve essere valida, sommare a uno entro tolleranza e concordare con la categoria selezionata.

Con provider indisponibile o decisione incerta, si possono rendere deterministicamente
solo campi scalari esplicitamente richiesti di un record con identità verificata.
Una citazione documentale letterale non salva una parafrasi incerta. Se un passaggio
necessario supera il budget viene escluso interamente, senza tagliarlo per ottenere
artificialmente un'approvazione. Le tabelle usano record originali filtrati per ambito e identità.

I messaggi distinguono assenza di risultati, aggiornamento/verifica indisponibile,
evidenza non verificabile, conflitto e chiarimento. Una parte valida sopravvive al
fallimento delle altre; la risposta parziale esplicita il limite.

Configurazione (`config/reasoning.php`):

```dotenv
CHAT_REASONING_ENABLED=true
CHAT_REASONING_MAX_EVIDENCE=100
CHAT_REASONING_MAX_STATE_BYTES=65536
CHAT_PARALLEL_RESEARCH_ENABLED=true
CHAT_RESEARCH_CONCURRENCY=4
CHAT_RESEARCH_TIMEOUT_SECONDS=90
AGENT_SELECTIVE_VALIDATION_ENABLED=true
AGENT_SELECTIVE_VALIDATION_THRESHOLD=0.8
```

I flag sono indipendenti. Disabilitare `AGENT_SELECTIVE_VALIDATION_ENABLED` ripristina
il validatore precedente senza eliminare memoria o fonti. Per un rollback completamente
deterministico disattivare anche `AGENT_BATCH_SEMANTIC_GROUNDING_ENABLED` e
`AGENT_SEMANTIC_GROUNDING_ENABLED` (definiti in `config/agent.php`). I controlli
fonte/citazione rimangono; anche l'interruttore
di grounding disattivato conserva un sottoinsieme verificabile con testo e fonti,
anziché azzerare le affermazioni. Verificare sempre la configurazione effettiva dopo il deploy.

## Installazione e riavvio

Migrazione **additiva**, nessun reset, seed o ingest:

```sh
php artisan migrate --path=database/migrations/2026_09_29_180000_add_reasoning_state_to_chat_owners.php --force
php artisan config:clear
php artisan queue:restart
```

In produzione usare la normale procedura `migrate --force` e ricostruire `config:cache`.
`queue:restart` fa terminare i worker dopo il job corrente: Supervisor/Horizon o il
process manager devono riavviarli. Con un worker manuale rilanciare il comando esistente,
per esempio `php artisan queue:work redis --queue=agent,kb-ingest,default --sleep=1 --tries=3`.
Riavviare Horizon tramite la procedura del deploy se presente. Non avviare `artisan serve`:
in locale il sito resta servito da Herd su `https://askmydocsdev.test`.

Per le ricerche multiple, configurare **prima del riavvio** anche i tempi della coda:

```dotenv
AGENT_JOB_TIMEOUT=900
REDIS_QUEUE_RETRY_AFTER=4200
# Se si usa invece la coda database:
DB_QUEUE_RETRY_AFTER=4200
```

Il timeout del job deve essere inferiore al `retry_after` della connessione. La
configurazione storica locale aveva retry dopo 90 secondi: troppo presto per un turno
con più indagini. Senza `AGENT_JOB_TIMEOUT` rimane il timeout storico di 120 secondi.
Il timeout per processo di ricerca è distinto: 90 secondi per ramo, con al massimo
quattro processi contemporanei. Cambiare concorrenza o numero di task richiede di
ricontrollare il budget complessivo. I lock impediscono a una riconsegna concorrente
del padre di risalvare un risultato mentre i figli stanno ancora lavorando.

Questa estensione **non richiede un'altra migrazione**, né reset, seed, ingest,
refresh dei connettori o build frontend. Estende i JSON di stato già presenti.

Le chat esistenti iniziano con stato vuoto e recuperano riferimenti storici su richiesta,
sempre dopo riconvalida. Nessuna reinterpretazione o migrazione massiva della cronologia.
Non fare rollback della migrazione durante il rollback dei flag: il JSON inutilizzato è innocuo.

## Debug e valutazione

### Stile delle risposte per argomento

La sintesi può proporre `section_title`, un'etichetta editoriale breve e neutra
nella lingua della risposta. Per i task indipendenti il server la rende come
titolo `##`, separato dai fatti, invece di usare `subquestions[].question`.
Titoli interrogativi, copie della domanda, markup, più righe o più di 100 caratteri
vengono sostituiti con un'etichetta deterministica dell'aspetto e gli identificativi
del focus. Il campo è facoltativo per compatibilità con checkpoint e provider
precedenti. Se nessun fatto del task è conservato, si usa sempre il titolo neutro
del server, non un possibile titolo-conclusione della bozza.

Le istruzioni richiedono titoli nominali per argomento, paragrafi brevi, elenchi
per condizioni parallele, numerazione solo per sequenze, enfasi contenuta e valori
tecnici esatti in codice inline. Vietano di ripetere la domanda, introdurre ogni
frase con lo stesso identificativo o aggiungere riepiloghi ridondanti. Una sezione
sulle regole deve iniziare dalle condizioni operative, non ripetere il profilo
dell'entità. I fatti restano atomici: il compositore conserva i testi approvati e
le citazioni, senza una nuova chiamata di riscrittura o valutazione.

Questa modifica riguarda la presentazione: non modifica budget, soglie, selezione
delle evidenze o rifiuti JEV. Il campo resta nel payload della bozza e nel debug;
retry e continuazioni non generano una nuova bozza solo per ottenere un titolo.
Per il rilascio basta riavviare i worker; non servono build frontend o migrazioni.

### Riferimenti incompleti e budget JEV nelle domande multiple

Il prompt di sintesi contiene `claim_source` con le tre proprietà JSON da copiare:
`document_id`, `tool_execution_id`, `evidence_hash`. Se il modello omette entrambe
le identità canoniche (ad esempio genera una chiave errata `document_id=null,`),
il server può ricostruirle **solo** quando l'hash corrisponde a una sola fonte
nell'envelope autorizzato della stessa sottodomanda. Non interpreta chiavi errate,
non sovrascrive identità esplicite e non cerca altre fonti nel database.
Scope, hash, record, codici e citazione originale devono comunque superare tutti
i controlli successivi. Un hash ambiguo non viene risolto automaticamente.

La richiesta JEV usa `state.sources`, un dizionario di testi originali senza
duplicati. Ogni coppia contiene `source_ref`, gli eventuali riferimenti alle altre
evidenze pertinenti e `subquestion_id`. I passi non vengono tagliati e le fonti
contraddittorie non vengono eliminate per far entrare più candidati. Una coppia
che supera ancora il limite resta non valutata; la sua aggiunta non consuma spazio
per le coppie successive. Provenienza e autorizzazione restano distinte anche se
due esecuzioni hanno restituito lo stesso testo.

Il debug `response.grounding.semantic_validation[]` espone `state_layout`,
`state_bytes`, `state_max_bytes`, `pair_count`, `source_count` e, nei controlli
recuperati, `source_resolution.method=unique_evidence_hash`. Da questa correzione
`state_byte_encoding=sanitized_json_utf8` indica che i byte sono misurati **dopo**
la stessa mascheratura di segreti/PII applicata dal servizio Decisions. I debug
precedenti senza questo campo misuravano lo stato prima della mascheratura.

Il limite applicativo predefinito passa da 16.000 a **32.768 byte**
(`DECISION_MAX_STATE_BYTES=32768`). Il margine fisso di 1.000 byte viene eliminato:
un payload che rientra nel limite effettivo non perde più un intero fatto a causa
di quella riserva. Non cambia il limite separato di 12.000 byte sulle domande.
Non cambiano soglia 0,80, massimo di dieci coppie, singola chiamata JEV e gestione
degli esiti incerti. È un limite di dimensione, non un criterio di approvazione.

`state_limit_count` e `claim_limit_count` indicano i candidati non valutati per
ciascun limite; un controllo con `reason=state_limit` contiene anche
`state_bytes_with_candidate` e `state_overflow_bytes`. Non si tratta di un no di
JEV né di un'assenza della fonte. Un candidato oltre budget non entra nella
richiesta e non consuma spazio a danno dei candidati successivi. Nessuna fonte
necessaria o contraddittoria viene accorciata per aggirare il limite.

La finestra JEV documentata è 32.000 **token** per stato e domande insieme, non
32.000 byte. Il limite locale non sostituisce quello del provider: prima di
aumentarlo ancora misurare token complessivi, costo e latenza anche su testi
multilingua. I dettagli del contratto sono in `docs/decision-models.md`.

Regressione anonimizzata: `tests/Fixtures/Agent/multi-question-source-validation.json`,
derivata dalla chat 37. Comprende quattro domande, nove affermazioni, tre documenti
e due risultati MCP. Con esiti simulati positivi tutte e nove arrivano alla
valutazione e conservano le citazioni originali. Nel diagnostico reale del
30 settembre 2026, JEV `typesafe/jev-1.13-20260917` ha valutato nove coppie in una
chiamata: sette approvate, due incerte (0,51 e 0,76), tutte e quattro le domande
con almeno un fatto approvato. Stato: 11.798 byte, quattro testi originali unici;
latenza provider circa 619 ms, costo riportato 0,000306516 USD. Nessuna approvazione
implicita per i due fatti incerti. È una prova diagnostica, non una calibrazione
statistica del modello né una garanzia sul prossimo output generativo.

Seconda regressione: `tests/Fixtures/Agent/multi-question-state-budget.json`,
anonimizzata dalla chat 39, contiene nove fatti e undici testi diversi, anche
sovrapposti. Nel replay locale del JSON originale lo stato completo occupa
16.538 byte: tutti i nove candidati ora arrivano in un'unica richiesta, senza
`state_limit`. Le risposte di questo replay sono simulate per verificare
confezionamento e limiti, **non** per attestare approvazione semantica reale.
I test coprono anche budget esatto, un byte oltre limite, mascheratura che
modifica la dimensione, Unicode e conservazione delle fonti contraddittorie.

Se l'ambiente ha un override esplicito a 16.000 byte, aggiornarlo a 32.768.
Eseguire `php artisan config:clear` (o ricostruire la cache in produzione), poi
il riavvio graduale dei worker. In locale senza override vale il nuovo default.

Rilascio di questa correzione: `php artisan queue:restart`, verificando il riavvio
dei worker tramite il process manager. Nessuna migrazione, reset, seed, ingest o
build frontend. Per provarla inviare una **nuova richiesta**: le risposte già salvate
e le decisioni dei run conclusi non vengono riscritte o rivalutate automaticamente.

### Integrità e interruzione dello stream eventi

I publisher delle ricerche parallele condividono un contatore sotto lock del run.
L'append prende il massimo tra contatore e log persistito: un vecchio contatore
arretrato non può provocare un duplicato. L'istanza chiamante sincronizza soltanto
`last_sequence` come già salvato, senza marcarlo dirty: salvare un checkpoint dopo
un observer non deve riscrivere un numero precedente.

Lo stream scarica tutto il log, anche oltre 100 eventi e con un contatore storico
arretrato. Se il run è fallito/annullato ma manca l'evento terminale, restituisce uno
snapshot di trasporto (`data.status_snapshot=true`), localizzato e senza diagnostica
privata. La lettura non modifica il DB né ripete ricerche. I tentativi di riconnessione
su EOF vuoto sono distanziati e annullabili. Un collegamento interrotto senza risposta
salvata o conferma terminale non viene più visualizzato come «Risultato pronto».

Per le richieste già fallite occorre inviare una nuova domanda dopo il rilascio;
non vengono riavviate automaticamente. Nessuna migrazione o ripopolamento dati.

Verifica della correzione: 97 test backend e 650 frontend superati, build completa
SPA/widget. Prova reale su Herd della domanda hub/spedizione: 27 eventi con contatore
coerente, risposta parziale salvata e visibile, nessun errore di stream. La parte
spedizione resta non verificata semanticamente: il ripristino del trasporto non
aggira il validatore e non equivale a confermare tutte le informazioni trovate.

### Richieste vocali: avanzamento e serializzazione

La voce continua a usare lo stesso turno canonico della chat. Durante la chiamata
`askmydocs.chat_turn`, il client cerca il run appena creato tramite la ricevuta
`GET /conversations/{conversation}/realtime-agent/{session}/run?call_id=...`
(polling ogni 750 ms, solo finché la chiamata è pendente). La ricevuta è di sola
lettura e controlla tenant, proprietario, conversazione, sessione attiva e call ID.
Appena disponibile, il client apre lo stream eventi esistente: domanda, lista dei
task e avanzamento sono visibili senza attendere la risposta vocale finale.
Il fallimento dell'osservazione UI non impedisce di consegnare la risposta al provider.
La chiusura di Live interrompe il polling e ignora i callback tardivi; non annulla
automaticamente la ricerca canonica già avviata.

Una conversazione può avere un solo turno agente principale non terminale. Il browser
respinge subito la seconda chiamata; il server applica la stessa regola sotto lock
della conversazione, anche da un'altra scheda o sessione vocale. Il risultato voce
è `busy=true, retry=false` con il messaggio «Un attimo, una cosa alla volta. Sto ancora
completando la richiesta precedente.» (localizzato). Un invio testuale concorrente
riceve HTTP 409. Non si creano messaggi o run aggiuntivi e non si sostituisce il primo.
I task paralleli **dentro la stessa richiesta** restano consentiti. Anche un turno
che attende conferma rimane occupato finché viene continuato, annullato o concluso.
Il vincolo riguarda l'esecuzione della richiesta, non la durata della riproduzione audio.

Per distribuire questa modifica: `bun run build`, normale pulizia/ricostruzione
delle cache del deploy e `php artisan queue:restart`. Non servono migrazioni o seed.
Ricaricare la pagina e chiudere/riaprire Live, perché le istruzioni vocali sono fissate
all'apertura della sessione. Regressioni automatiche: `RealtimeAgentIntegrationTest`,
`use-realtime-agent.test.tsx`, `use-agent-chat.test.tsx` e `voice-chat-activity.test.tsx`
(provider Fake, con tool pendente e stream eventi reale del client). Smoke locale
OpenAI eseguito su Herd con l'account fixture: evento `session.started`, sessione
stabile durante gli aggiornamenti UI e chiusura pulita. L'ingresso audio era silenzioso
e sintetico: riconoscimento e riproduzione della voce reale restano da provare manualmente.

Nel download JSON della chat:

- `conversation.reasoning_state`: stato compatto corrente;
- messaggio utente `metadata.reasoning`: focus precedente e interpretazione del turno;
- risposta `metadata.reasoning`: revisione, focus dopo il salvataggio e riferimenti registrati;
- run `question_understanding`, azioni con `purpose`, `response.grounding.semantic_validation`:
  candidato stabile, fonte, controlli PHP, categoria/probabilità, fallback, esclusioni,
  ripetizioni, modello, costo e latenza;
- `focused_decision`: invio/risultato durevole, riutilizzato nei retry.
- `reasoning_state.objective` e `subquestions`: obiettivo aggiornato e task persistenti;
- `research_kb_flows`: query e risultati KB distinti per task;
- `research_runs` / `research_flows`: run figli registrati dal server, stato, motivo
  di arresto e ID delle esecuzioni; gli eventi dei figli arrivano allo stream del padre
  con `research_flow_id`;
- `research_drafts`: bozza di ogni task, firma delle evidenze e stato di generazione.

Il JSON resta un export amministrativo autenticato e tenant-scoped; non è un endpoint
pubblico della memoria. Valgono le protezioni già presenti su credenziali e PII.

```sh
# Mostra i casi anonimizzati, senza chiamate remote.
php artisan decision:reasoning-eval --json
# Valutazione reale: comporta il piccolo consumo AI mostrato nel risultato.
php artisan decision:reasoning-eval --live --json
```

Il comando non legge né modifica dati aziendali. Le fixture riproducono gli errori
ordine/spedizione, cambio di focus hub → spedizione, supporto parziale, conflitto,
codice cliente senza cifre e ripetizione. I test includono isolamento, fonti revocate,
documenti modificati, riapertura, turni fuori ordine, limiti e retry.

Verifica della prima consegna: **124 test mirati, 645 asserzioni**, tutti passati (memoria,
preprocessore, agente, KB, widget, voce, debug e dominio Decisions). Lint PHP e
`git diff --check` superati. È una suite mirata, non l'intera suite del repository.
Il formatter Pint non è installato nel progetto; non sono state aggiunte dipendenze.

Rilevazione diagnostica locale del 30/09/2026 su 7 casi, JEV `typesafe/jev-1.13-20260917`:
zero false approvazioni, zero rifiuti errati ad alta confidenza, tre astensioni
(una su un caso corretto: probabilità 0,77), zero ripetizioni, costo totale
0,000208068 USD, latenza cumulativa 2683,93 ms. Non è una calibrazione statistica:
ampliare il campione etichettato prima di cambiare soglia o modello.

Verificato anche il preprocessore reale `openai/gpt-4o-mini`: con una relazione
registrata spedizione → cliente, “Who is the customer?” produce focus `customer`,
`mentions=[]`, riferimento contestuale al cliente e campo richiesto `name`.
Il prompt distingue esplicitamente il focus precedente da quello richiesto ora;
uno schema senza sottodomande attive viene rifiutato anziché riusare il vecchio focus.

Nota sul worktree: il diff preesistente in `AgentClaimBatchEvaluator.php` è stato
preservato, non incorporato nella modifica. Tre test legacy falliscono con quel diff;
la stessa suite del vecchio evaluator caricata dalla versione HEAD passa (12 test).
Il nuovo percorso usa `FocusedClaimEvaluator`, separato dal file locale preesistente.

## Obiettivi contestuali e domande indipendenti (30/09/2026)

L'interprete strict riceve l'ultimo messaggio, l'obiettivo persistente, i task precedenti,
i riferimenti autorizzati e gli ultimi dodici atti del dialogo in ordine. I messaggi
futuri non sono visibili. Del bot passano stato della risposta, domande affrontate e
chiarimenti: la sua precedente prosa aziendale non diventa una nuova fonte.

Ogni sottodomanda include `question` (domanda autonoma, riformulata nella lingua
dell'utente) e `kb_queries` (da una a tre ricerche solo per quel task). Ci possono
essere fino a dieci task; quattro richieste non vengono più rifiutate perché producono
più di tre query globali. Il focus principale deve comparire anche tra i task: se il
modello lo omette, il server lo recupera usando esclusivamente query già validate per
quell'identificativo, senza includere i codici degli altri task.

Esempi:

- «parlmi dell'hub X e cercami l'ordine Y» → due indagini autonome, senza assumere
  una relazione tra X e Y;
- «quindi?» dopo una domanda su ORDER-A → una domanda contestuale esplicita su ORDER-A
  oppure un chiarimento, mai la query letterale `quindi` se l'interpretazione riesce;
- «ora parti dall'hub» → solo il task hub, non la vecchia lista di richieste.

Se il modello confonde un codice letteralmente presente con un riferimento contestuale,
il server può correggere **solo** questa distinzione con un confronto testuale esatto.
Un codice inventato resta rifiutato. Fallimento del preprocessore conserva la memoria
e il fallback sulla domanda esplicita; non ricostruisce arbitrariamente riferimenti.

Ogni ramo KB recupera e valuta le proprie fonti. Nel percorso agente, ogni ramo
pianifica separatamente le chiamate live, poi produce una bozza con soltanto le fonti
di quel ramo. Il budget di chiamate del padre è ripartito, non moltiplicato per i figli.
Le fonti restano vincolate a tenant, progetto, utente, connettore e hash. Un risultato
di un altro run non è valido solo perché appartiene alla stessa conversazione: deve
essere un figlio registrato del padre oppure uno snapshot autorizzato per un recap.

La sintesi dell'agente concatena sezioni verificate, senza un'ulteriore riscrittura
globale. JEV riceve una sola richiesta con decisioni legate alle rispettive domande;
il server non sposta un'affermazione da un ramo a un altro per farla passare. Il limite
resta dieci decisioni totali, distribuite tra i task. Per più record, ogni affermazione
resta associata a un singolo record originale; i campi possono essere resi in tabelle
Markdown nella sezione, e una raccolta che eccede il budget viene dichiarata parziale.
Le tabelle interattive delle richieste singole restano invariate. Una conferma/input
MCP necessario interrompe quel ramo: non viene autorizzato automaticamente.

`CHAT_PARALLEL_RESEARCH_ENABLED=false` disattiva la separazione in processi e ripristina
il pianificatore unico, mantenendo lo stato della conversazione. È separato dai flag
di memoria e di validazione. Non svuotare lo stato per fare rollback.

Verifiche aggiuntive: quattro domande con errori ortografici e «quindi?» provati anche
con il preprocessore reale `openai/gpt-4o-mini`; processi realmente sovrapposti e
timeout di un singolo ramo; test di query/prompt isolati, ruolo autenticato ripristinato,
esclusione di fonti di altri task, retry, fallimento di una bozza e una sola chiamata JEV.

Verifica finale di questa estensione: **171 test mirati, 888 asserzioni**, tutti passati
(Chat unit/feature, Agent, KB Investigation, WidgetAgentRun, RealtimeAgentIntegration,
FocusedClaimEvaluator e SelectiveSynthesis). Include la conservazione dei riferimenti
ai task durante deduplicazione e riuso storico esplicito. Lint PHP e `git diff --check`
superati. Non è un'esecuzione dell'intera suite del repository.

Diagnostica JEV reale su due fatti sintetici indipendenti: il record della spedizione
è stato approvato a 0,99; la frase documentale corretta sull'hub è risultata incerta
a 0,51 e non è stata ammessa come risposta narrativa. Una richiesta, 477,93 ms,
0,00005124 USD. La separazione dei task non risolve da sola la calibrazione del
giudice semantico; la soglia 0,80 è rimasta invariata, senza approvazioni implicite.

### Lista delle richieste nell'interfaccia

Il riquadro attività della chat mostra, prima dei dettagli operativi, le domande
autonome individuate quando ce ne sono almeno due. `research.planned` pubblica la
lista subito dopo l'interpretazione, prima della ricerca documentale; `research.task`
aggiorna la singola riga tramite `research_flow_id`. I processi figli scrivono nello
stream del padre: il numero della richiesta compare anche accanto alle chiamate.

«Fonti raccolte» non significa «risposta verificata». Solo `research.finished`,
prodotto dopo la sintesi e la validazione, espone l'esito di ogni domanda. Un esito
globale `run.completed` non rende automaticamente verdi tutte le righe. Errori,
interruzioni, conflitti e verifiche insufficienti rimangono distinguibili.

Gli eventi conservano solo domande e stati pubblici, attraverso il mascheramento
esistente, senza aggiungere ragionamenti interni o payload delle fonti. La stessa
lista è ricostruita dagli eventi salvati nelle informazioni della risposta dopo la
riapertura. Le chat precedenti prive di questi eventi mantengono l'interfaccia precedente.
Per il rilascio servono il bundle frontend aggiornato e il riavvio dei worker, non
migrazioni, seed o reset.
