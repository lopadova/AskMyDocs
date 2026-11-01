# Collaudare la chat sul server via API

Il client `scripts/qa/chat-api.py` usa le stesse conversazioni, lo stesso
`AgentMessageController`, la stessa coda e lo stesso worker della chat web.
Non avvia una seconda pipeline e non richiede accesso al pannello Laravel Cloud
per ciascun test. Le risposte rimangono visibili anche nella chat dell'utente
che possiede il token.

## Attivazione una tantum

1. Pubblicare questo branch e distribuire il codice nell'ambiente di sviluppo.
2. Un operatore esegue sul server, per un utente attivo già membro del tenant:

   ```sh
   php artisan chat:issue-api-token EMAIL_UTENTE --tenant=autry --hours=168 --debug
   ```

   Il comando restituisce **una credenziale segreta in JSON**, una sola volta.
   `--debug` richiede il ruolo admin o super-admin. Ometterlo per un normale
   utente: invio, risposte, citazioni, strumenti ed eventi rimangono disponibili.
   La durata predefinita è 24 ore, il massimo 168. Non vengono creati utenti o
   modificati ruoli.
3. Conservare quel JSON sul computer in un file privato fuori dalla repository,
   ad esempio `/private/tmp/askmydocs-chat-token.json`, con permessi `600`.
   Non inviarlo in chat. Se il comando gira da shell locale con accesso al
   server, usare `umask 077` prima della redirezione verso il file.
4. Importare e verificare il profilo locale:

   ```sh
   python3 scripts/qa/chat-api.py init \
     --base-url https://ask-my-docs-develop-5oikgu.laravel.cloud \
     --project autry --token-file /private/tmp/askmydocs-chat-token.json
   python3 scripts/qa/chat-api.py check
   ```

Il profilo predefinito è `~/.config/askmydocs/chat-api.json`, privato, con il token.
Per più ambienti usare `--profile PERCORSO` prima del sottocomando, oppure
`ASKMYDOCS_CHAT_PROFILE`. Il client legge il token dal file, non dagli argomenti
visibili del processo. Il token è limitato a un tenant e alla superficie
`/api/chat/*`; resta soggetto alle appartenenze, ai progetti e ai ruoli correnti.
Un token scaduto o revocato deve essere riemesso dall'operatore.

La creazione del profilo non contatta il server. `check` verifica invece
l'autenticazione reale, il tenant, i progetti e la scadenza senza chiamare un
modello. Questa repository non contiene token di un server reale.

## Eseguire una domanda e un seguito

```sh
python3 scripts/qa/chat-api.py ask \
  --question 'Incrocia U858724, U858798 e RU106083 e confronta i due ordini.' \
  --output /private/tmp/askmydocs-qa/caso-255 --debug
```

Il riepilogo stampa `conversation_id` e `run_id`. Per un seguito nella stessa
conversazione:

```sh
python3 scripts/qa/chat-api.py ask --conversation ID_CONVERSAZIONE \
  --question 'Quali informazioni mancano ancora?' \
  --output /private/tmp/askmydocs-qa/caso-255-seguito --debug
```

`--input-json FILE` aggiunge gli stessi input della chat: `depth`, `filters`,
`live_sources`, `mcp_app_id` e `selection`. Per esempio:

```json
{
  "depth": 3,
  "filters": {"languages": ["it"]},
  "live_sources": {"api": ["api:gescat"], "mcp": []}
}
```

Gli identificativi delle sorgenti vanno letti dal catalogo reale con `sources`;
`api:gescat` sopra è solo un esempio. Omettere `live_sources` mantiene la scelta
predefinita della chat. `selection` contiene il `message_id` della risposta
con tabella e il `row_key` restituito dal server: non si inventano ID o record.
Le chiamate usano modelli, autorizzazioni e limiti reali dell'utente e possono
consumare credito AI e chiamare i connettori abilitati.

Ogni directory contiene:

- `submission.json`: input e ricevuta con gli ID; scritto prima dell'invio.
- `run.json`: ultimo stato, contatori, strumenti e diagnostica se autorizzata.
- `events.json`: tutti gli eventi persistiti, letti a pagine con cursore.
- `messages.json`: risposta completa con metadati, citazioni, tabelle,
  riferimenti agli strumenti e attività, tramite lo stesso controller della UI.
- `debug.json`: export diagnostico completo della conversazione se autorizzato.
- `summary.json`: esito e identificativi. Tutti i file sono privati (`600`).

Il client non ripete automaticamente POST che potrebbero essere già arrivati al
server. Una directory che contiene una submission non può inviare una seconda
volta. Se la conferma di invio è andata persa, ispezionare `submission.json`,
`conversations` e `messages --conversation ID`; non reinviare alla cieca.
Il limite `--wait` riguarda l'osservazione locale: alla scadenza il job sul server
non viene annullato. Si può riprendere:

```sh
python3 scripts/qa/chat-api.py watch --run UUID_RUN \
  --output /private/tmp/askmydocs-qa/caso-255 --debug
```

Codici di uscita: `0` completata o parziale, `1` fallita/annullata,
`2` errore client/HTTP, `3` serve un'interazione, `124` attesa locale scaduta.
Una risposta parziale non è un test funzionale riuscito: verificare contenuto,
fonti, completezza e risultato atteso nei file salvati.

## Casi demo conversazionali: almeno tre turni

Ogni caso demo richiede almeno tre domande dell'utente nella **stessa
conversazione**, quindi almeno trenta turni per una tornata di dieci casi.
La prima domanda resta quella originale del caso, per confrontare il risultato
con le tornate precedenti. Le domande successive si scelgono dopo aver letto la
risposta precedente: non sono una sequenza di prompt prefissata né tre richieste
indipendenti in chat nuove.

Chi conduce il collaudo assume due prospettive:

- **Utente che approfondisce:** formula ogni seguito usando soltanto la domanda
  iniziale e ciò che è già emerso nella chat. Non anticipa fatti, date, codici,
  nomi o risposte mancanti appresi dalle fonti di verifica, dagli export o da
  prove precedenti. Un'affermazione della chat resta da verificare: non diventa
  vera perché viene ripresa in una domanda.
- **Verificatore:** confronta le risposte con documenti, risultati API e
  diagnostica autorizzati. Registra errori, omissioni e contraddizioni anche
  quando un seguito ottiene una risposta migliore.

Per il secondo turno scegliere un dubbio concreto emerso nella prima risposta:
un riferimento ambiguo, una differenza tra documenti e gestionale, un passaggio
temporale o un'affermazione senza prova. Per il terzo approfondire un altro
aspetto in base alle prime due risposte, per esempio la prova di un esito, un
collegamento tra ordini o le informazioni ancora necessarie all'operatore.
Se un aspetto è già chiarito, cambiare direzione invece di ripetere la domanda.
Una risposta negativa documentata è valida; non insistere finché il sistema
produce il risultato atteso.

Prima di ogni seguito salvare in un file privato `follow-up-decision.json`:
numero del turno, ID della risposta precedente, breve passaggio che ha motivato
la scelta, aspetto da approfondire, domanda effettiva e motivazione. La domanda
deve avere senso per un utente che ha letto soltanto quella conversazione.
Le fonti di verifica e i risultati attesi restano separati dagli input inviati.

Usare `--conversation ID` per i seguiti e directory distinte, per esempio
`TORNATA/DEMO-01/turn-01`, `turn-02` e `turn-03`. Attendere la chiusura della run
precedente e ispezionare la risposta prima del prossimo invio. Non contare tre
subdomande nello stesso messaggio come tre turni. Non sostituire una chat
fallita con una nuova conversazione per presentare il caso come riuscito:
registrare l'impedimento ed eventuali turni mancanti. Un invio incerto richiede
la normale riconciliazione in lettura, senza reinviare automaticamente.

Il report conserva per ciascun turno domanda, motivo del seguito, chat/run,
risposta, fonti, strumenti, tempi ed esito verificato. Valuta anche la continuità
del contesto: clienti e ordini non scambiati, riferimenti impliciti risolti,
fatti già emersi conservati, contraddizioni e dati nuovi attribuiti alle fonti.
Distinguere la copertura al primo turno dalla copertura dopo approfondimento:
un recupero al terzo turno non cancella un'omissione iniziale. Riportare sia i
turni effettivamente eseguiti sia i casi con almeno tre turni completati.

Completare tutti e dieci i dialoghi prima dei fix applicativi, accorpare i
malfunzionamenti e poi ripetere la tornata dopo commit e deploy. Nei confronti
fra deploy il primo prompt resta uguale; i seguiti restano adattivi e il report
deve evidenziare le differenze, senza dichiararli una ripetizione identica.

## Ispezione e interazioni

```sh
python3 scripts/qa/chat-api.py sources
python3 scripts/qa/chat-api.py snapshot --run UUID_RUN
python3 scripts/qa/chat-api.py messages --conversation ID
python3 scripts/qa/chat-api.py debug --conversation ID
python3 scripts/qa/chat-api.py document --id ID_DOCUMENTO
python3 scripts/qa/chat-api.py tool-source --conversation ID --message ID --execution ID
python3 scripts/qa/chat-api.py cancel --run UUID_RUN
python3 scripts/qa/chat-api.py continue --run UUID_RUN --logical 5 --physical 20
python3 scripts/qa/chat-api.py reconcile --run UUID_RUN
python3 scripts/qa/chat-api.py revoke
```

Il client si ferma su conferme, selezioni o handoff MCP/API. Non concede
estensioni di budget automaticamente. `continue` usa il contratto di conferma
budget della chat; selezioni di righe passano come input del prossimo turno.
Le interazioni MCP che richiedono azioni dell'utente rimangono handoff espliciti;
questo client non automatizza OAuth o approvazioni di strumenti.

`reconcile` è una riparazione esplicita per run già terminali, riservata ad
admin/super-admin con `chat:debug` e accesso alla propria chat. Chiude strumenti
rimasti aperti ed emette gli eventi mancanti, marcando l'esito fisico come
sconosciuto. È idempotente, non cambia la risposta e non riesegue richieste.
Una run attiva viene rifiutata con `409`.

## Contratto HTTP

Tutte le richieste richiedono `Authorization: Bearer TOKEN`, `X-Tenant-Id` e
`Accept: application/json`. I POST mandano JSON. Non servono cookie o CSRF.

| Metodo e percorso | Risultato |
| --- | --- |
| GET `/api/chat/me` | Autenticazione, tenant, progetti, scadenza |
| GET/POST `/api/chat/conversations` | Elenco/creazione chat dell'utente |
| GET `/api/chat/live-sources?project_key=...` | Catalogo API/MCP autorizzato |
| POST `/api/chat/conversations/{id}/messages` | `202`, run reale accodata |
| GET `/api/chat/conversations/{id}/messages` | Stessi messaggi della UI |
| GET `/api/chat/runs/{uuid}?after=N` | Stato e fino a 100 eventi, `next_after`, `has_more` |
| GET `/api/chat/runs/{uuid}/events` | Stream SSE esistente, ripresa con `after` |
| POST `/api/chat/runs/{uuid}/cancel` | Annullamento tramite il controllo standard |
| POST `/api/chat/runs/{uuid}/continue` | Estensione confermata dei budget |
| POST `/api/chat/runs/{uuid}/reconcile` | Riparazione admin di strumenti aperti in una run terminale |
| GET `/api/chat/conversations/{id}/debug-transcript` | Export admin, richiede `chat:debug` |
| GET `/api/chat/documents/{id}/preview` | Testo fonte autorizzato |
| GET `/api/chat/conversations/{id}/messages/{message}/tool-sources/{execution}` | Risultato strumento registrato |
| DELETE `/api/chat/token` | Revoca della credenziale corrente |

Anche una run terminale può avere altre pagine di eventi: consumare `has_more`
prima di considerare completo il log. `diagnostics` nello snapshot è disponibile
con `chat:debug` e ruolo amministrativo, anche durante l'esecuzione. Non è una
sonda OS: log del container, OOM e traffico mai persistito non sono ricostruibili
tramite queste API.

## Budget del worker e riprova

Nel worker ogni richiesta testuale usa il tempo rimasto della sua invocazione,
con un massimo predefinito di 60 secondi e una riserva di 10 secondi per verifica
e chiusura, oltre al margine finale. Il timeout arriva sia ai provider HTTP sia
al percorso SDK. Le decisioni Jev rispettano il tempo residuo e non eseguono
retry nascosti dentro la stessa invocazione. Fuori da questo contesto i retry
ordinari rimangono invariati.

`AGENT_TEXT_REQUEST_TIMEOUT_SECONDS` e `AGENT_TEXT_REASONING_EFFORT` configurano
il default testuale (`60`, `low`): OpenRouter invia l'effort soltanto ai modelli
che ne dichiarano il supporto. `AGENT_WAVE_PROCESS_CONCURRENCY=2` limita i
bootstrap PHP simultanei indipendentemente dalla larghezza logica della wave.
La diagnostica distingue `wave_concurrency` e `wave_process_concurrency`.

Per i thread lunghi verificare `DECISION_MAX_STATE_BYTES=131072`: un override
esplicito precedente a 32.768 continua a prevalere. Controllare nel nuovo export
il limite effettivo e la concorrenza dei processi prima di confrontare le due
tornate. Usare conversazioni nuove e gli stessi prompt; conservare gli export
precedenti senza sovrascriverli.

Se il contesto complessivo supera quel limite, grounding e copertura usano
richieste separate: `shared_sources_batched_v1`, `logical_state_bytes` e
`batches` distinguono dimensione complessiva e dimensione effettiva massima.
Ogni confronto conserva la propria fonte primaria completa e passa su tutte
le alternative pertinenti. Un conflitto o un risultato mancante impedisce
l'approvazione del claim; il controllo di copertura prende la probabilità
minima fra i blocchi. Una singola fonte/confronto indivisibile ancora troppo
grande resta `state_limit`, senza tagli o esclusioni silenziose.

`requested_source_coverage_required` identifica invece un piano che cerca solo
documenti quando la richiesta esige anche dati di una sorgente live selezionata.
Il planner riceve questo errore prima dell'esecuzione e corregge la proposta.
Non è una conferma dei dati né un'autorizzazione a operazioni di scrittura.

## Verifica locale

I test HTTP usano token Sanctum reali, lo stack delle rotte e l'handler del
worker. Provider e retrieval sono fixture; non contattano servizi esterni.
Il client ha test per ripresa delle pagine, file privati, credenziali,
invio incerto senza duplicazione e scadenza dell'osservazione senza annullamento.
Il funzionamento sul server va verificato dopo deploy e provisioning del token.

Gli export distinguono anche il componente in cui l'interpretazione si ferma
(`failure_details.component`). Un errore del contratto di focus conserva un
codice chiuso in `contract_error`, riusato dal solo tentativo di correzione
previsto. Non vengono copiati corpi HTTP, header o messaggi arbitrari del provider.

Per i seguiti, la navigazione conserva nomi e valori dei soli campi identità
dichiarati dal connettore, con tool e percorso del record. Gli estratti di
affermazioni mostrate nella chat (al massimo dieci da 240 caratteri per risposta)
servono soltanto a capire riferimenti come «le due registrazioni». Sono ammessi
solo da fonti ancora autorizzate e selezionate e non sostituiscono rilettura,
aggiornamento, timestamp o verifica del supporto dei fatti.
