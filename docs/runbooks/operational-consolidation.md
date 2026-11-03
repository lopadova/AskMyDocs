# Consolidamento operativo — campagna di sviluppo

## Stato del ciclo

La prima strumentazione è partita da `develop` (`7852a14`); il seguito usa
`codex/feature-operational-consolidation-pipeline`, basato su `01092f5`. La fase 0 comprende:
strumentazione, prenotazioni condivise, corpus fisso e procedura. **Non costituisce
la baseline live e non abilita l'inizio delle fasi 1–6.** Marco ha ora autorizzato
l'agente anche a push, merge su `develop` e deploy di sviluppo; restano obbligatori
PR, revisioni e gate del repository. La produzione è esclusa.
Marco ha autorizzato il deploy delle tre variabili QA: il deploy di configurazione
184 è concluso sul codice `01092f5`. Le verifiche correnti sono nel dossier di
avanzamento; i risultati simulati restano separati dai turni baseline.

| Gate | Evidenza richiesta | Stato iniziale |
|---|---|---|
| G0a | Primo deploy, revisione riconoscibile, migrazione QA, web e worker allineati | Verificato su deploy 184 |
| G0b | Prenotazioni concorrenti verificate sul database di sviluppo; nessuna inferenza durante la prova | Superato su PostgreSQL, due processi; costo zero |
| G0c | Dieci dialoghi baseline di almeno tre turni, fonti originali riesaminate, costi riconciliati | Primo turno fallito per controllo identità embedding; campagna arrestata |
| G1 | Tempo e ciclo di vita consolidati, fixture superate | Attende G0c |
| G2 | Identità delle fonti e riuso autorizzato, fixture superate | Attende G1 |
| G3 | Verifiche indipendenti e tentativi durevoli, fixture superate | Attende G2 |
| G4 | Fatti e blocchi conservati nella composizione, fixture superate | Attende G3 |
| G5 | Privacy, connettori e provenienza dei costi, fixture superate | Attende G4 |
| G6 | Client/build pertinenti, deploy candidato autorizzato, dieci dialoghi candidato e rollback verificato | Attende G5 |

Il risultato storico 18/30 e i dialoghi già presenti sono materiale di partenza,
non prove di questo ciclo. I test con risposte HTTP simulate sono separati dal
collaudo live e dal test locale di concorrenza fra processi reali.

## Confini della strumentazione

- `qa_campaigns` serializza le prenotazioni con un lock di riga; SQLite usa un
  lock di scrittura prima della lettura. Importi interi in miliardesimi di USD.
- `qa_inference_attempts` conserva hash del payload, modello, percorso, run,
  round/caso/turno, prenotazione, costo, fonte del costo, durata e classi chiamanti
  per correlare le inferenze agli stadi diagnostici. Gli argomenti dello stack
  non vengono catturati.
  Non conserva prompt, risposte, corrispondenza cliente o credenziali.
- Il middleware HTTP copre i trasporti Laravel HTTP e Laravel AI SDK usati da
  chat, embedding e Jev. Il contesto viene ripristinato nei worker delle wave e
  della ricerca; i processi condividono il database, non un contatore in memoria.
- Ogni tentativo è prenotato **prima** del dispatch. `speso + prenotato + nuova
  prenotazione` deve rientrare nel budget. Un errore di rete non viene considerato
  gratuito; i tentativi di retry incontrano la campagna arrestata.
- Un costo assente può essere riconciliato con una GET al registro generazioni
  del provider. Se resta sconosciuto, la prenotazione resta impegnata e la
  campagna si arresta. Callback ripetute non addebitano due volte.
- Trasporti/modelli non approvati, stream, fallback automatici, modalità non
  testuali e output non limitato vengono bloccati. Jev senza limite di output è
  ammesso solo con tariffa output verificata pari a zero.
- Per il testo il plugin web è disabilitato esplicitamente sulla richiesta:
  ometterlo può ereditare un default dell'account. Preset, scorciatoia `:online`
  e strumenti web del provider sono esclusi dal perimetro delle fonti QA.
  Riferimento: [contratto plugin OpenRouter](https://raw.githubusercontent.com/OpenRouterTeam/docs/main/guides/features/plugins.mdx).
- La prenotazione usa byte UTF-8 come limite prudenziale dell'input e il massimo
  output ammesso. Per testo viene imposto anche il tetto di prezzo del provider,
  preservando un eventuale tetto preesistente più basso. Non vengono cambiati
  modelli, temperature, limiti di token o soglie dell'agente.

Il registro impedisce nuovi dispatch oltre le prenotazioni consentite; non è un
limite di spesa del conto presso il provider. Un addebito del provider superiore
alla prenotazione viene registrato e arresta la campagna, ma non può essere
annullato dal middleware. Il costo ignoto non diventa mai zero. Le tariffe del
manifesto vanno riesaminate prima del round candidato: se non sono verificabili,
non si procede. Riferimento: [routing e tetti di prezzo OpenRouter](https://openrouter.ai/docs/guides/routing/provider-selection).

## Deploy della strumentazione — operazioni autorizzate

1. Portare su sviluppo i commit locali della fase 0 tramite il normale Gitflow.
   Verificare lo stato delle migrazioni; applicare la nuova migrazione QA.
2. Impostare **soltanto sull'ambiente di sviluppo**:

   ```dotenv
   ASKMYDOCS_QA_CAMPAIGNS_ENABLED=true
   ASKMYDOCS_DEPLOYMENT_TIER=development
   APP_RELEASE=<SHA Git immutabile effettivamente distribuito>
   ```

   Cloud develop può avere `APP_ENV=production`; il tier esplicito identifica
   questa destinazione. Entrambe le opzioni sono necessarie fuori da local/test.
   Lasciare invariati provider, modelli, prezzi applicativi, soglie e limiti.
3. Laravel Cloud applica le migrazioni con i deploy commands già previsti dal
   progetto. Non aggiungere `queue:restart`: i worker vengono sostituiti dalla
   piattaforma. Se il progetto usa `config:cache`, eseguirlo in build e non nei
   deploy commands. [Ciclo di deploy Cloud](https://laravel.com/cloud/docs/environments#unnecessary-build-and-deploy-commands).
   Dopo il deploy, nella console di sviluppo:

   ```sh
   php artisan migrate:status --path=database/migrations/2026_11_02_160000_create_qa_campaign_tables.php
   php artisan qa:campaign runtime --tenant=autry --project=autry --actor=10
   php artisan qa:campaign probe --tenant=autry --project=autry --actor=10
   ```

   Controllare che web e tutti i worker usino lo stesso `APP_RELEASE`. Il tier e
   la revisione non vanno dedotti dal nome del branch. La run conserva revisione
   e configurazione fotografate alla ricezione; un worker con revisione diversa
   rifiuta di eseguirla.
4. Archiviare l'output del probe: deve indicare `simulated_database_reservations`,
   due processi, risultati `blocked`/`reserved`, costo e prenotazione finale zero,
   `inference_calls=0`, driver PostgreSQL e la revisione distribuita. Il probe
   usa due processi applicativi e connessioni DB distinte, sincronizzati prima
   della prenotazione; verifica anche due callback di chiusura per lo stesso
   tentativo. Blocca ogni richiesta HTTP e crea esclusivamente una campagna
   simulata con `actor_id=0`, separata dalla campagna AI. La fixture resta
   tracciabile nel ledger e non può entrare nella chat. Non vale come collaudo AI.
5. Verificare accesso API, permessi diagnostici e sorgenti ammesse prima delle
   inferenze baseline.

Non avviare le fasi successive per aggirare il gate della baseline. Il controllo
di budget deve essere attivo sul server, anche se il client si interrompe.

## Corpus e manifesto

La preparazione privata corrente è in
`storage/app/private/qa/consolidation-20261102/`, esclusa da Git. Contiene dieci
prime domande, copiate senza riscrittura da dieci dialoghi storici distinti,
un indice con hash e un registro separato da completare con la valutazione delle
fonti originali. Hash dell'indice congelato:

`3c9a72978d54640a8ede758202b85a7477afb329fcf163b7983911ed610d5626`.

Gli input inviati all'agente sono solo i file in `inputs/` ed eventuali normali
filtri chat. `oracle-review.json` non deve essere incluso nei payload. Le risposte
storiche dell'assistente non sono state copiate come risultati attesi. La revisione
privata iniziale ora contiene 55 fatti con quote e hash di 34 preview. Le quote
sono state inoltre riscontrate letteralmente nei 34 file originali dello storage,
normalizzando gli spazi ed estraendo il testo del PDF; gli hash dei byte originali
restano distinti da quelli delle preview. Questo dimostra il supporto degli
estratti selezionati, non l'assenza di altri documenti. Si aggiungono 12 attese
su record ERP provenienti da 16 riscontri tecnici archiviati.
Uno dei dieci casi non aveva un riscontro ERP storico: verificarlo sulle fonti
ottenute nel nuovo round. Le attese sono storiche, non dimostrano l'immutabilità
dei record; confrontare sempre originali e date effettivamente recuperati live.
L'indice pubblico delle regressioni è in
`docs/qa/operational-consolidation-regressions.md`.

Per ricostruire una campagna da esportazioni private delle cronologie:

```sh
python3 scripts/qa/campaign.py freeze --history <cartella-cronologie-private> \
  --conversations <dieci-ID-distinti> --output <nuova-cartella-privata>
```

Il comando rifiuta di sovrascrivere un corpus esistente. Non esegue inferenze.
Prima di creare il manifesto, archiviare l'output `runtime` del server in un
file privato e confrontarlo con le configurazioni effettive del progetto.
Elencare tutti i modelli effettivamente selezionati: modello chat condiviso,
eventuali modelli di sintesi/repair/presentazione, preprocessor, embedding e Jev.
La scelta condivisa `ai.chat_model`, quando valorizzata, prevale sui modelli dei
singoli stadi. Non sostituire modelli per far passare il manifesto.

Creare un file privato `sources.json` con le chiavi esatte dei connettori ammessi:

```json
{"api": ["<chiave-reale-autorizzata>"], "mcp": []}
```

Un array vuoto seleziona nessun connettore di quel tipo. L'omissione nel turno
usa la lista della campagna e non allarga il perimetro. L'accesso a documenti e
record continua a essere governato dai normali controlli del progetto.

Il preparatore consulta solo il catalogo pubblico del provider e usa il massimo
delle tariffe degli endpoint pubblicati, comprese fasce per contesto lungo,
finestre orarie e scrittura cache, senza applicare sconti presunti. Il massimo
non dipende dalla previsione del tokenizzatore o dall'ora della richiesta.
Il [contratto tariffario del catalogo](https://raw.githubusercontent.com/OpenRouterTeam/docs/main/guides/overview/models.mdx)
descrive gli override. Prezzi assenti, negativi, supplementi
non gestiti e output Jev a pagamento impediscono la generazione:

```sh
python3 scripts/qa/campaign.py manifest \
  --index <corpus>/index.json --sources <privato>/sources.json \
  --text-model <modello-testo-effettivo> \
  --embedding-model <modello-embedding-effettivo> \
  --decision-model <modello-Jev-effettivo> \
  --output-reserve-tokens 16384 --output <privato>/manifest.json
```

Ripetere `--text-model` per gli altri modelli attivi. La riserva di 16384 token
deve coprire il massimo output già configurato, compreso il retry strutturato;
non cambia il massimo richiesto dalle chiamate. Esaminare tariffe, alias di
risposta, fonte e data prima di creare la campagna. Il catalogo non disponibile
impedisce la preparazione: non usare prezzi di default o gratuità presunta.

Sul server di sviluppo, caricato il solo manifesto tecnico, creare **una sola
campagna** per entrambi i round:

```sh
php artisan qa:campaign create --manifest=<manifesto-tecnico> \
  --tenant=autry --project=autry --actor=10
php artisan qa:campaign inspect --id=<UUID-campagna> \
  --tenant=autry --project=autry --actor=10
```

Il budget totale è 10 USD, non 10 per round, processo o campagna sostitutiva.
Se il ciclo richiede revisioni AI esterne, riservare loro una quota del medesimo
tetto: l'allocazione corrente prevista è 9 USD live e 1 USD per revisioni.
Una riduzione del tetto della campagna deve essere atomica, solo verso il basso,
compatibile con speso e prenotato e registrata nel manifesto con attore, motivo,
revisione e vecchio/nuovo limite. Conservare intatti addebiti e risultati.
La quota revisioni è un limite prudenziale, non un costo AI già sostenuto:
registrare separatamente consumi misurati, tariffa e copertura da abbonamento.
Per la revisione locale Copilot usare il limite di sessione e il file di uso;
[GitHub documenta un minimo di 30 crediti](https://docs.github.com/en/copilot/how-tos/copilot-cli/use-copilot-cli/set-session-limit)
e [1 credito = 0,01 USD](https://docs.github.com/en/billing/concepts/product-billing/github-copilot-billing).
La revisione cloud della PR ha invece consumo variabile e non va richiesta
quando non è possibile verificarne il tetto entro il residuo assegnato. Il budget
esplicito dell'utente prevale sull'avvio di revisioni AI non quantificabili:
conservare la revisione indipendente locale, i gate CI e l'eventuale eccezione
nel dossier, senza presentare un review cloud non eseguito come approvato.
Finché l'adeguamento non è applicato e riesportato, il tetto live resta quello
effettivamente presente nel ledger e non si riprendono inferenze.
Un manifesto superiore a 10 viene rifiutato. Non modificare i saldi manualmente.
Non creare un'altra campagna per superare un arresto o rinnovare il budget.

### Identità nativa degli embedding e riconciliazione

Il nome nativo `text-embedding-3-small` può essere restituito alla richiesta
`openai/text-embedding-3-small`. Non autorizza rimozioni generiche del prefisso.
Il middleware verifica la stessa generazione con una GET al registro del provider,
richiedendo ID, tipo `embeddings`, modello completo e costo identici. Conserva il
nome originale in `actual_model` e il riscontro in `identity_json`. Una GET fallita,
un costo sconosciuto o una discordanza continuano ad arrestare la campagna.
Il [registro generazioni del provider](https://openrouter.ai/docs/api/api-reference/generations/get-generation)
fornisce il riscontro; la lettura non genera una nuova inferenza.

Per una campagna già fermata da questo specifico controllo, dopo il deploy della
correzione QA e della migrazione `2026_11_03_100000_add_qa_model_identity_proof.php`:

```sh
php artisan qa:campaign reconcile-embedding-identity --id=<UUID-campagna-esistente> \
  --tenant=autry --project=autry --actor=10
php artisan qa:campaign export --id=<stesso-UUID> \
  --tenant=autry --project=autry --actor=10
```

Il comando verifica nuovamente il provider e controlla lo stato sotto lock.
Richiede un amministratore proprietario ancora autorizzato e tutte le run chiuse.
Gli stati terminali sono `completed`, `partial`, `failed` e `cancelled`: una
risposta parziale già finalizzata non viene scambiata per un worker ancora attivo.
Rifiuta costi ignoti, prenotazioni aperte, errori diversi, esaurimento dei limiti o
un cambiamento concorrente dello stato. Non modifica budget, addebiti,
prenotazioni o numero dei tentativi. Aggiunge la prova al tentativo conservandone
l'errore originario; un evento nel manifesto conserva motivo dell'arresto,
operatore, revisione e saldi. Non riavvia run né ripete POST. Un secondo comando
su una campagna già attiva non effettua altre letture provider o modifiche.

Il primo turno fallito resta parte dei risultati baseline. Gli eventuali turni
aggiuntivi sono numerati e valutati separatamente: non sostituiscono un esito
negativo o la prima domanda congelata.

## Esecuzione e valutazione

Usare il profilo API privato già predisposto, con attore amministratore e scope
`chat:use`, `chat:debug` e tenant corretto. Il campo `qa` è accettato soltanto
sull'API Bearer diagnostica; UI ordinaria e altri utenti non possono attivarlo.

```sh
python3 scripts/qa/chat-api.py check
python3 scripts/qa/chat-api.py campaign --id=<UUID-campagna>
python3 scripts/qa/chat-api.py ask \
  --campaign=<UUID-campagna> --round=baseline --case=CASE-01 --turn=1 \
  --question-file=<corpus>/inputs/CASE-01.txt --debug \
  --output=<privato>/baseline/CASE-01/turn-1
```

I turni 2 e 3 usano lo stesso `--conversation` restituito dal primo e gli stessi
campagna/round/caso; i seguiti sono scritti dopo aver letto la risposta precedente
e valutati separatamente. Per ciascuno usare un file privato distinto e `--turn`
corretto. Sono ammessi almeno tre turni: i turni aggiuntivi restano numerati nella
stessa campagna (massimo 255), consumano lo stesso residuo e non sostituiscono i
primi esiti. Ripetere sui dieci casi. Tutti i seguiti della conversazione richiedono
il medesimo contesto QA, anche passando da un'altra superficie della chat.
Campagna, round e caso restano immutabili nella conversazione; il numero del
nuovo turno deve superare tutti quelli già registrati. Il controllo avviene sotto
il lock della conversazione, prima di salvare messaggi o avviare nuovi job.
Cronologie precedenti con contesti discordanti vengono rifiutate esplicitamente.
La prima run QA richiede una conversazione senza messaggi o run precedenti e il
turno 1. Il controllo finale di
ammissione blocca anche la riga della campagna fino alla creazione della run:
un controllo HTTP precedente non autorizza l'inserimento dopo un arresto o una
riconciliazione concorrente.
L'operatore viene riletto dopo la verifica provider; ogni successiva ammissione
e ingresso nello scope di esecuzione rileggono l'identità e le autorizzazioni.
Lo stato attivo della campagna non costituisce un'autorizzazione dell'attore.

Se l'esito della POST è incerto, leggere `submission.json` e la cronologia:
**non ripetere la POST**. Se il client smette di attendere, usare `watch --run`;
il job server non è automaticamente cancellato. Non approvare automaticamente
conferme o azioni esterne richieste durante il collaudo.

La rubrica `qa-rubric-v1` controlla, per sottodomanda: supporto nelle fonti,
copertura, coerenza temporale, separazione dei casi, valori letterali,
autorizzazioni, confini delle tabelle e distinzione fra fatti e richieste cortesi.
Esiti ammessi: `pass`, `fail`, `unverifiable`. Ogni esito cita un originale;
un'aspettativa senza riscontro rimane non verificabile.

Archiviare separatamente per round e turno: revisione, configurazione, run UUID,
prima domanda/hash, seguito adattivo, fonti/date/versioni, risposta, valutazione,
latenze diagnostiche per stadio, chiamate per percorso/modello, omissioni,
fallback e costi. Il client salva run, eventi, messaggi e transcript diagnostico
in file 0600. Nessun file cliente va aggiunto alla repository.

Esportazione tecnica, utilizzabile anche con campagne disabilitate sullo stesso
ambiente di sviluppo:

```sh
php artisan qa:campaign export --id=<UUID-campagna> \
  --tenant=autry --project=autry --actor=10
```

L'export contiene manifesto e tentativi, senza testi cliente. Confrontare saldi
ed eventi del provider; una prenotazione pendente rimane impegnata. Un processo
interrotto può lasciare `pending`: dopo il tempo massimo del worker più margine,
una nuova prenotazione arresta la campagna invece di liberare fondi presunti.

Solo dopo G0c si sviluppano in sequenza `ExecutionBudget`, `EvidenceHandle`,
`VerificationResult`, `VerifiedFact`/`AnswerBlock`, poi privacy/connettori/costi
e client. Per ogni fase: opzione disabilitata inizialmente per sottosistema e
tenant/progetto, versione fotografata sulla run, adattatori per stati precedenti,
fixture pertinenti superate e commit locale atomico. Niente confronto parallelo
con inferenze AI duplicate. I confronti preliminari usano fixture.

Al secondo deploy usare `--round=candidate`, lo stesso UUID di campagna e gli
stessi file del primo turno, su dieci **nuove** conversazioni. I follow-up restano
adattivi. Non sostituire casi falliti e non contare i dialoghi storici nei nuovi
sessanta turni. Se il residuo termina, conservare tutto e dichiarare la validazione
live incompleta; la produzione rimane fuori dal ciclo.

## Rollback e criteri di chiusura

Per fermare nuove inferenze QA: impostare `ASKMYDOCS_QA_CAMPAIGNS_ENABLED=false`
e applicare il nuovo deploy di configurazione con il ciclo gestito da Cloud.
Le run QA in coda sui nuovi worker rifiutano
l'esecuzione; non diventano chat senza controllo. Una chiamata già accettata dal
provider non è annullata dal cambio di configurazione. Il registro e le run
restano leggibili, con autorizzazioni invariate, conservando il tier development.
Le conversazioni QA richiedono sempre il contesto QA per i seguiti: per la chat
ordinaria aprire una nuova conversazione. Non eliminare tabelle o stati pendenti.

Il secondo rollback, delle opzioni dei sottosistemi consolidati per le nuove run,
sarà implementato e verificato nelle relative fasi; non è attestato da questa
consegna. Ogni stato già persistito deve rimanere leggibile.

La chiusura richiede tutte le regressioni deterministiche pertinenti superate,
nessun accesso non autorizzato/effetto duplicato/codice alterato/perdita di fatti,
nessun peggioramento delle aspettative già soddisfatte, valutazione indipendente
live e dossier comparativo completo. Un errore preesistente estraneo resta
registrato e distinto dai risultati di questo ciclo.
