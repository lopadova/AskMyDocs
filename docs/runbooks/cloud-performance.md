# Prestazioni dell'intero servizio AskMyDocs — piano operativo

Stato al 22 ottobre 2026, Europe/Rome. Le modifiche e le prove descritte come locali
sono nella worktree `codex/askmydocs-cloud-performance`. Nessuna modifica Cloud,
migrazione remota, chiamata AI, invio email, ingestione, push o pubblicazione eseguita.
Il [report locale](../performance/2026-10-22-results.md) distingue i risultati dalle ipotesi.

## Evidenze e perimetro

| Fonte | Fatto verificato | Cosa non dimostra |
| --- | --- | --- |
| Dump `chat-debug-80-20261022-152218.json` | Run 186 / `6f0f6e56-af32-4ba4-b03b-705f99198d79`, coda attesa 560 ms, interpretazione 6.549 ms, due rami KB; lookup ancora aperti all'export | Durata finale, numero riconsegne, OOM, latenza di una sorgente API assente |
| Payload e stack documentati in `conversation-reasoning.md` | Job Cloud `1ca25ec5-0df4-4f9b-9d8e-9cf508d7bf92`, default, timeout 120 s, maxTries 1; interruzione dentro la query vettoriale | Che il worker sia morto per RAM o che abbia ricevuto il messaggio più volte |
| Log successivo riportato nella diagnosi | Lookup 172 ms, prima ricerca vettoriale 85,5 s, seconda interrotta; stato failed ed evento finale registrati | Identità con ogni run del dump, piano SQL reale, successo di un deploy completo |
| JSON Cloud fornito da Marco | PG 18.6, vector 0.8.1, pg_trgm 1.6; stime 98.077 documenti / 160.563 chunk; totali 379 / 2.965 MB; trigrammi validi/pronti già presenti; marker del percorso iterativo assente | Che HNSW sia rotto, che tutti i progetti debbano usarlo, che un solo marker attesti l'intera release |
| EXPLAIN Cloud precedente | Piccolo progetto campionato, stima una riga, BitmapAnd tenant/progetto e PK documento | Il piano della ricerca da 85,5 s o il tempo di esecuzione: non era ANALYZE |
| PostgreSQL locale | PG 18.0/vector 0.8.1, 1.558 chunk, corpus molto più piccolo; trigrammi non applicati | Dimensioni, working set, cache, carico o risorse del server Cloud |

Le scansioni storiche degli indici (HNSW zero, trigrammi una ciascuno) vanno lette
insieme a finestra di carico e reset. `hnsw.iterative_scan=off` generale è compatibile
con il fix che usa `SET LOCAL` nella transazione. Non rimuovere permessi o filtri,
non ridurre soglie di similarità e non cambiare modelli per far passare un benchmark.

## P0 — Allineare codice e caso prima di attribuire i tempi

Il principale aveva i commit `e09aaa3a9` (lookup/trigrammi), `b053a58cd` (failed),
`f50077675` (ordinamento/iterazione pgvector), poi `f1ce5a2e5` (correlazione job).
La worktree iniziale aveva 55 commit esclusivi e ne mancavano 421 del principale.
Il ramo di questo lavoro parte da **f1ce5a2e5**, senza riscrivere o duplicare quei fix.
Il test dirty del principale `tests/Feature/Agent/AgentChatModelScopeTest.php` non
è stato copiato né modificato. Non unire automaticamente i due alberi divergenti.

Prima di un futuro rilascio Marco verifica l'ascendenza del ramo destinatario:

```bash
git merge-base --is-ancestor f1ce5a2e5 codex/askmydocs-cloud-performance
git log --oneline f1ce5a2e5..codex/askmydocs-cloud-performance
git diff f1ce5a2e5..codex/askmydocs-cloud-performance
```

Se il ramo di pubblicazione diverge, integrare con revisione dei conflitti; non
reapplicare alla cieca i tre commit. Registrare SHA distribuito, `APP_RELEASE`
immutabile, versione di ogni worker e revisione dei pacchetti. L'hash del file
su disco letto da Commands non attesta codice già caricato da un worker.

Nel caso reale costruire una riga per run con: queue dispatch/receive, inizio/fine
SQL, embedding cache/provider, lettura fonti, rerank, provider di interpretazione,
sintesi e validazione, attese dei figli, stato/evento finale. Usare gli span già
presenti (`RunDiagnostics`) e il debug esportabile. **Non sommare fasi annidate o
rami paralleli**: distinguere tempo trascorso e lavoro cumulativo. Confrontare
stesso tenant/progetto/principal/filtri/K/soglia/corpus/query embedding/versione;
conservare questi input solo nel sistema autorizzato, senza pubblicarli nel report.
La run studiata ha fonti KB: l'assenza della sorgente API GESCAT è un controllo
separato di disponibilità/autorizzazione, non una lentezza PostgreSQL.

## P0 — Una raccolta Cloud, di sola lettura

Da questa worktree generare il comando portabile per **Laravel Cloud → Commands**:

```bash
python3 scripts/performance/cloud-command.py --run 186 > /private/tmp/askmydocs-performance-cloud-command.txt
```

Aprire il file e incollare **l'intera unica riga** in Commands nell'ambiente
interessato. È il sorgente verificabile `scripts/performance/cloud-snapshot.php`,
compresso per non dover distribuire file nuovi; richiede PHP/zlib. Non eseguirlo
automaticamente su target remoti. Restituire il JSON, annotando l'ora del problema.
Per una nuova run sostituire solo `--run` in fase di generazione.

Il raccoglitore usa il DB già configurato: transazione READ ONLY con rollback,
statement timeout 1.500 ms, lock timeout 250 ms, attesa idle massima 5 s, limiti
sulle righe di statistiche e due campioni di attività distanti 0,5 s. Non esegue
ricerche vettoriali, EXPLAIN ANALYZE, CREATE EXTENSION, ANALYZE, reset di statistiche,
HTTP/AI, query su contenuti/input/risultati del run o scritture di cache. Una stima
EXPLAIN riguarda solo la popolazione tenant/progetto/documenti attivi della run,
**non** la query reale completa con ACL e filtri. I campi espressione del piano
sono rimossi ricorsivamente. Tenant, progetto, vettori, SQL, credenziali, indirizzi
client ed eccezioni complete non vengono stampati. Errori/permessi mancanti
producono `unavailable_sqlstate`, senza interrompere le altre sezioni.

Contiene: hash di file/lock, configurazione tecnica allowlisted del processo
Commands, versione/estensioni, supporto disponibile/installato/precaricato di
pg_stat_statements, dimensioni heap/indici, date statistiche, contatori DB/I/O,
connessioni/attese/blocchi aggregati, stato e fasi della run, runtime registrato
dal worker quando disponibile. I contatori cumulativi non sono velocità I/O;
due campioni brevi possono mancare una contesa intermittente.

Alla stessa raccolta allegare dal pannello Cloud, per la finestra **17:21–17:24
Europe/Rome del 22 ottobre** e per una nuova riproduzione controllata se disponibile:

| Pannello | Dati necessari |
| --- | --- |
| Database | Tier/vCPU/RAM attuale, CPU/memoria, I/O/latency/IOPS o metriche offerte, connessioni/limite, eventi di sleep/wake, dimensione e limiti autoscaling effettivamente disponibili |
| Queue / worker / compute | Tipo managed queue o worker custom, pool/numero processi e min/max, RAM per processo, backlog/età messaggi, throughput, eventi autoscaling, exit code/motivo riavvio e job ID/tentativo |
| Connessione | Endpoint pooled o diretto, modalità/limite del pool, disponibilità endpoint diretto; nessuna password necessaria |
| Usage | Costo osservato nella finestra, tariffa e configurazione minima/massima attuale; nessun cambio piano |

Il raccoglitore non può ottenere queste metriche del provider tramite SQL.
La raccolta resta utile anche se la run è vecchia o le tabelle degli span mancano.
Localmente il run numerico 186 è un'altra run (12 ottobre) e la tabella degli span
non è ancora applicata: non confonderlo con la run Cloud del 22 ottobre.

## P1 — Query, larghezza dei risultati e qualità

Già mantenuti: ordinamento diretto `<=> ASC` e strict_order con vector >=0.8,
fallback esatto sulle versioni precedenti, soglia invariata, filtri prima della
selezione dei candidati, finalizzazione dopo errore definitivo. I due trigrammi
Cloud esistono: non ricrearli per tentare di risolvere il problema vettoriale.

**Implementato localmente in questo ramo:** la query pgvector restituisce solo
i campi usati da mapping/rerank/runner-up, incluso il FK del documento. Il vettore
resta nel calcolo SQL e non viene trasferito per ciascun candidato. Il fallback
coseno in PHP continua a caricare gli embedding. Prova locale: valori trasferiti
485.772 → 22.892 byte per 24 candidati (−95,3%), stessi campi di retrieval,
ordine, punteggi e recall nel campione; vedere il report per i limiti.

**Da decidere sui piani reali:** una sottoquery materializzata top-K di ID/distanza
seguita da fetch contenuti può ridurre il lavoro sulle colonne grandi. Non è
stata introdotta: cambia forma del piano e numero di round trip, e il vantaggio
va misurato. In particolare non spostare filtri ACL, documenti attivi o soglie
all'esterno del top-K. Verificare anche FTS, lookup multipli e lettura documenti
interi: non estendere automaticamente una micro-ottimizzazione ad altri percorsi.

Per il caso reale servono il piano effettivo e la selettività di tutti i filtri.
Indici composti tenant/progetto o parziali si valutano solo se stime/righe reali,
buffer e frequenza del workload ne mostrano il vantaggio rispetto a BitmapAnd o
al percorso HNSW. Non forzare globalmente enable_seqscan e non regolare alla
cieca shared_buffers/work_mem su un servizio gestito.

Per EXPLAIN ANALYZE: prima una copia rappresentativa autorizzata o staging senza
provider; SELECT del caso con vettore già registrato, budget iniziale 1.500 ms
per statement e una sola connessione. Se il piano stimato indica lavoro troppo
grande, fermarsi prima di eseguirlo. Ogni aumento di budget sul server è una
decisione esplicita di Marco. `EXPLAIN ANALYZE` esegue davvero la query.
Salvare solo nodi/righe/tempi/buffer: escludere Filter/Index Cond/Sort Key/Output,
che possono contenere i binding. Non svuotare cache né riavviare il DB di produzione
per fabbricare una prova fredda. Distinguere primo passaggio, caldo e wake-up
osservato; il primo passaggio non certifica cache fredda.

Confrontare ANN con ricerca esatta su stessi input nel perimetro autorizzato;
recall@K, candidati prima/dopo soglia e risultati dopo ACL/folder. Strict_order
ordina i vicini restituiti ma non rende ANN esatta: i limiti di scansione possono
ridurre il recall. Il confronto locale su un vettore non certifica la qualità
su progetti grandi, query diverse o documenti esclusi.

## P1 — Worker, fanout e timeout

`ResearchFanout` usa processi PHP, fino a `reasoning.research_concurrency` (default 4).
Lo usano il coordinatore agent e l'investigazione KB. `ApiToolCollector` ha un
fanout distinto, fino a 5 richieste dettagli; è assente nel caso KB studiato.
I figli ereditano il contesto autorizzato e possono tenere connessioni anche
quando il padre aspetta. Il numero dei worker Cloud da solo non descrive il
numero delle query/processi. Contare i rami realmente sovrapposti negli span;
non moltiplicare automaticamente tutti i massimi di percorsi diversi.

La simulazione locale ha provato 1/2/4 job × 4 rami, cioè 4/8/16 processi fisici,
ciascuno con 3 SELECT: nessun errore SQL. È carico SQL simulato, non un benchmark
del job completo. Prima di aumentare il parallelismo Cloud ripetere su una copia
rappresentativa, includendo connessioni del padre, diagnostica, API e ingestione
concorrente presenti nel workload. Gate: p95 non peggiora oltre il 20% rispetto
al riferimento per lo stesso carico, niente timeout e margine su RAM/connessioni.
Se c'è saturazione, provare prima di tutto meno rami o meno worker su staging,
registrando throughput ed età della coda: meno concorrenza non è sempre più veloce.

Isolare chat interattiva da ingestione, reembedding, import ed email triage se
backlog o contesa lo giustificano. Le code applicative sono già configurabili
(`agent.queue`, `kb.ingest.queue`, altre code specifiche); verificare i pool Cloud
che le ascoltano prima di cambiare una destinazione. Creare pool/queue e regolare
min/max sono azioni Cloud future, con quota e costo verificati. Non cambiare
AGENT_QUEUE senza predisporre il consumatore e gestire i messaggi già in coda.

Coordinare durata dei figli, budget complessivo del job e timeout dei provider con
margine per cleanup/finalizzazione. Su Redis/database il lease supera il timeout
del job; su Cloud la visibilità è gestita ed estesa dal servizio: `retry_after=null`
non equivale a 90 s. Il payload già accodato conserva timeout/tentativi serializzati.
Non aumentare i timeout per nascondere SQL da 85 s. Non rilanciare automaticamente
un job fallito: può ripetere chiamate, costi o operazioni esterne.

## P2 — Cache e connessioni

La cache embedding esiste: chiave testo mascherato/hash + provider + modello;
deduplica già i miss nello stesso batch. Il riuso riguarda vettori, non risultati
KB autorizzati. `searchWithContext` riusa l'embedding tra primaria e runner-up;
le investigazioni evitano contesto ausiliario inutilizzato; i loop hanno un
insieme di query già viste per ramo. Non è dimostrata una cache risultati globale
né una deduplica sicura tra rami con contesti diversi.

**Implementato localmente:** aggiornamento LRU in batch sui soli ID in cache
effettivamente letti e con timestamp dirty. La prova fake con due hit distinti
passa da 2 UPDATE a 1; ripetizioni e ordine invariati, altri modelli/provider e
testi non usati esclusi, zero UPDATE se il timestamp è già corrente. Nessun nuovo
livello di cache, nessuna modifica di TTL/chiavi o invalidazione del corpus.

Prossima misura: hit/miss e chiamate provider effettive, query ripetute nello stesso
turno e loro contesti, costo LRU/lock. Gli span `embedding_cache_or_provider`
separano la fase, ma non danno già un hit-rate. Qualsiasi futura cache dei risultati
richiede tenant, progetto, principal/ACL, filtri, modello/config e revisione del
corpus; invalidazione per modifica/revoca/delete. Il solo hash del testo non basta.

Confrontare picco connessioni/attese con limite DB e pool, distinguere connessioni
attive e idle e startup dei processi. Verificare modalità del pool e supporto
transazionale a SET LOCAL; il fix apre la transazione prima dell'impostazione.
Operazioni come build concorrenti degli indici richiedono esecuzione fuori
transazione: verificare con il provider l'endpoint diretto e i prerequisiti,
senza ricavare o chiedere password. Non modificare pooling o configurazioni remote qui.

## P2 — Risorse e osservabilità dell'intero servizio

La tabella da 2.965 MB include gli indici; non è la RAM necessaria né la sola
dimensione HNSW. Il raccoglitore separa heap/indici e dimensione di ciascun indice.
Per un upgrade servono working set, andamento CPU/RAM, letture e latenza disco,
connessioni e min/max reali, insieme al workload. In assenza di questi dati non
è giustificata una proposta di tier più grande.

| Collo misurato | Prova successiva prima di spendere | Eventuale azione futura e rollback |
| --- | --- | --- |
| CPU satura con SQL corretto | Profilo query, riduzione concorrenza con throughput controllato | Tier CPU solo con costo attuale/proposto; tornare al tier precedente se il gate fallisce |
| Letture elevate / working set fuori RAM | Bytes HNSW, buffer reali, I/O e latenza, cache calda/fredda osservata | RAM/IOPS se disponibili e utili; ripristino dimensione precedente dopo finestra concordata |
| Connessioni/pool in attesa | Worker × rami e limiti pool, idle e leak | Limitare fanout/pool o scalare il limite supportato; ripristinare configurazione documentata |
| Backlog ingestione che ritarda chat | Tempi coda distinti, throughput e risorse per classe | Pool separati entro quote/costo, rollback del routing mantenendo consumer delle vecchie code |
| Latenza provider | Tempi API separati da coda/DB, cache e retry | Nessun cambio modello o nuova inferenza in questo incarico |

Stima costi soltanto dopo il pannello: differenza prezzo/ora × ore istanza
attese, range minimo/massimo dell'autoscaling e costo per 100 ricerche completate,
più storage/I/O/cache/provider effettivamente tariffati. Registrare anche l'errore
e il costo dei tentativi falliti. Quota, prezzi e piano attuale non sono noti;
non inventare importi o promettere risparmi complessivi dai soli byte risparmiati.

pg_stat_statements non era nell'output Cloud: disponibilità, preload e view vanno
verificati, insieme al supporto del provider. Non abilitarlo qui: richiede
prerequisiti lato server. Se indisponibile usare span applicativi sanitizzati e,
solo con una modifica futura revisionata, metriche SQL aggregate senza binding,
testi, vettori o contenuti. Anche il log delle query normalizzate può contenere
dati: non esportare `query` indiscriminatamente.

Il servizio include web/admin/chat, SSE/polling, job/ingest/import/reembedding,
API e provider. Per web misurare p50/p95 HTTP autenticato, query per richiesta,
bundle/TTFB/stream e rendering; per ingestione throughput/documenti al minuto,
backlog e contesa. Non avviare ingest/import per misurare qui. Il vecchio piano
Lighthouse è in `docs/performance-audit.md`: usare Herd HTTPS, profilo autenticato
e build esistente, senza cambiare preferenze di velocità della chat.

## Gate, riproduzione e rilascio futuro

| Gate | Soglia iniziale da convalidare | Stato |
| --- | --- | --- |
| Lookup identificativo | p95 circa 1 s per casi rappresentativi | Dato 172 ms riportato; p95 reale non raccolto |
| SQL vettoriale | p95 pochi secondi sul corpus Cloud reale | Non convalidato; il millisecondo locale non lo sostituisce |
| Qualità/accesso | Nessuna fonte non autorizzata; recall e risultati accettati rispetto al riferimento esatto approvato | Parità su un vettore locale + test filtri; manca matrice Cloud/staging |
| Errori | Nessuna chat queued/running dopo fallimento definitivo gestibile | Regression test failed() verdi; SIGKILL/DB down restano casi separati |
| Concorrenza | 1/2/4 job con fanout reale, p95/throughput/RAM/connessioni e nessun timeout | Simulazione SQL locale completata; risorse Cloud mancanti |
| Regressioni | Provider fake/offline, filtri, runner-up, vecchia estensione, cache, failure | Report e test mirati locali |

Su staging/copia autorizzata preparare almeno: progetto piccolo, grande, filtro
documento/source/language/folder, tenant diverso, ACL revocata, hit e miss cache
con provider fake. Vettori già disponibili; stessa versione del corpus e seed
registrato. Alternare A/B e B/A dopo warm-up di entrambe le forme; conservare i
campioni individuali e p50/p95 (almeno 20, preferibilmente 100 per cella quando il
budget lo consente), righe/candidati/soglia/recall, buffer/I/O, errori, CPU/RAM e
costo. Non definire p95 di servizio da dieci query. Misurare le chiamate AI a parte:
i gate SQL non fissano una latenza totale promessa.

Comandi locali ripetibili (PHP 8.5 Herd, nessun `artisan serve`):

```bash
cd /Users/marco/www/AskMyDocsDev
'/Users/marco/Library/Application Support/Herd/bin/php85' /Users/marco/.codex/worktrees/3d27/AskMyDocsDev/scripts/performance/local-vector-benchmark.php
python3 /Users/marco/.codex/worktrees/3d27/AskMyDocsDev/scripts/performance/local-concurrency.py --php '/Users/marco/Library/Application Support/Herd/bin/php85' --app-root /Users/marco/www/AskMyDocsDev --fanout 4
```

Il benchmark rifiuta APP_ENV diverso da local, host non loopback, corpus >10.000;
timeout 1.500 ms, transazioni con rollback. Esegue soltanto SELECT e un EXPLAIN
ANALYZE per forma sul piccolo corpus locale. Il driver concorrente ferma soltanto
i propri figli, con deadline, e non gestisce worker o servizi dell'applicazione.

Checklist futura di Marco, dopo revisione/integrazione e autorizzazione al rilascio:

1. Salvare riferimento distribuito e configurazione/risorse correnti, verificare
   commit e test; applicare soltanto eventuali migration effettivamente pendenti.
   I trigrammi Cloud sono già validi: controllare lo stato senza ricrearli.
2. Pubblicare con la procedura Cloud concordata e sostituire/riavviare i worker
   tramite il servizio; verificare nuove run con APP_RELEASE/runtime attesi.
   La riga Commands non è una prova di riavvio del pool.
3. Canary su casi registrati con provider fake o percorso di sola lettura approvato,
   stesso caso prima/dopo, stato/evento finale in caso di timeout. Non rilanciare
   i 50 test reali della chat fermata e non riattivare monitor sospesi.
4. Controllare gate tecnici e qualità; se falliscono, tornare alla release
   precedente approvata o revert **separato** della sola proiezione/LRU. Non usare
   reset/amend, non rimuovere failed(), ACL, soglie o trigrammi per annullare una
   modifica del codice. La proiezione e LRU non richiedono rollback dello schema.
5. Solo dopo i risultati valutare indici, routing code, pooling e risorse con costo,
   limite di spesa e rollback concordati. Nessuna di queste azioni è eseguita qui.

## Fonti primarie consultate

- [PostgreSQL 18 — esame uso indici](https://www.postgresql.org/docs/18/indexes-examine.html):
  piani e statistiche rappresentative; corpus minuscoli non predicono produzione.
- [PostgreSQL 18 — pg_stat_statements](https://www.postgresql.org/docs/18/pgstatstatements.html):
  prerequisiti preload/query ID/estensione, da verificare col provider.
- [pgvector v0.8.1 — README della versione](https://github.com/pgvector/pgvector/blob/v0.8.1/README.md#iterative-index-scans):
  ordine diretto dell'operatore, scansioni iterative e limiti ANN.
- [Laravel Cloud — compute](https://laravel.com/cloud/compute) e
  [managed queues](https://laravel.com/cloud/docs/queues): pool separati, scaling
  e visibilità gestita; le funzionalità offerte non attestano la configurazione
  dell'account di Marco. Nessun prezzo o limite attuale dell'account è stato inferito.
