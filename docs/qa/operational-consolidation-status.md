# Dossier di avanzamento — 3 novembre 2026

La strumentazione della fase 0 è attiva su sviluppo e il controllo concorrente
PostgreSQL è verificato. Il primo turno della baseline ha rilevato un errore nel
controllo dell'identità embedding; la campagna è arrestata in attesa della
correzione QA. Le fasi 1–6 attendono il completamento della baseline.

## Codice e perimetro

- Strumentazione integrata in `develop`: `01092f5122ceb18467cf01d5ffbfcd189d13cff9`.
- Commit funzionali: `30251bb8a`, `17f3a4d6c`, `83f93df0a`.
- Nuovo branch locale per il seguito: `codex/feature-operational-consolidation-pipeline`,
  dalla revisione integrata; le modifiche alla pipeline attendono la baseline.
- Due tabelle QA additive, attivazione inizialmente disabilitata, vincolo esplicito
  all'ambiente development, campagna appartenente a tenant/progetto/attore.
- Prenotazione condivisa prima del dispatch, contabilità idempotente, arresto su
  addebito ignoto, modello non ammesso, prenotazione insufficiente o scaduta.
- Copertura dei trasporti applicativi HTTP/SDK, embedding, Jev e processi figli;
  revisione fotografata sulla run e rifiuto di worker con revisione diversa.
- Export tecnico, fonte del costo, durata della chiamata e classi chiamanti senza
  argomenti, hash del payload; nessun nuovo archivio di testi cliente nel ledger.
- Client e preparatore del corpus con file privati, manifesto a tariffe verificate
  tramite sole letture del catalogo e rubrica `qa-rubric-v1`.

Le politiche della pipeline non sono state consolidate anticipatamente: modelli,
soglie e limiti restano quelli rilevati. Marco ha integrato e distribuito la
strumentazione; nessun push o PR è stato eseguito dall'agente. Il 3 novembre
Marco ha autorizzato l'agente ad applicare le tre variabili QA e il relativo
deploy di configurazione sul solo ambiente di sviluppo.

## Verifiche locali precedenti alla revisione del branch

| Verifica | Esito | Limite dell'evidenza |
|---|---|---|
| Suite Agent/Chat/Unit Agent/OpenRouter/Architecture | 654 test, 653 superati, 1 fallimento preesistente; 3662 asserzioni | Include verifica identità embedding e turni aggiuntivi |
| QA e isolamento tenant dopo identità embedding e turni aggiuntivi | 32 test, 171 asserzioni, superati | HTTP provider simulato; due processi applicativi reali sullo stesso SQLite |
| Client HTTP e preparazione del corpus/manifesto | 17 test superati | Fixture locali; non attestano inferenze live |
| Sintassi PHP e controllo whitespace del diff | Superati | Non sostituiscono il collaudo di sviluppo |

Il fallimento esteso è
`GraphExecutorNotAdoptedTest::test_the_host_does_not_invoke_the_graph_executor`:
rileva `OperationNode.php -> FlowNodeHandler`. Test e file applicativo sono
identici a `origin/develop`; non è stato indebolito il controllo. La questione di
tenancy dei dati Flow segnalata dal test rimane una verifica separata da risolvere
prima di attestare una CI complessivamente verde.

Comando della suite estesa:

```sh
php vendor/bin/phpunit tests/Feature/Agent tests/Feature/Chat tests/Unit/Agent \
  tests/Unit/Ai/OpenRouterProviderTest.php tests/Architecture --no-progress
```

I test di prezzo distinguono valori mancanti da zero esplicito; quelli di costo
verificano che una connessione incerta non consenta ulteriori tentativi e che una
callback ripetuta non conteggi un nuovo addebito. Il test concorrente sincronizza
due processi prima di due prenotazioni incompatibili con lo stesso saldo: una è
ammessa e una bloccata. La prova PostgreSQL su sviluppo rimane da svolgere dopo
il primo deploy.

## Preparazione privata e attività live

La preparazione iniziale ha usato sole letture delle cronologie e delle fonti
autorizzate sul progetto `autry`. Dopo il deploy 184 è stato eseguito il primo
turno nuovo, conservato con il suo esito fallito come descritto sotto.

- Dieci prime domande congelate, con hash individuali, in file 0600 esclusi da Git.
- Indice del corpus: `3c9a72978d54640a8ede758202b85a7477afb329fcf163b7983911ed610d5626`.
- Sedici riscontri delle fonti storiche archiviati con sole GET, tutti disponibili.
- Schede di valutazione separate dagli input: 55 fatti con riscontro letterale
  nei file originali e 12 attese ERP; le risposte storiche non sono l'oracolo.
- Campagna unica di sviluppo creata; modelli e tariffe verificati con sole GET.
- Nuova baseline: 0/10 dialoghi completi; 1 turno eseguito, fallito, su 30 minimi.
- Candidato: 0/10 dialoghi, 0/30 turni.
- Spesa AI di questo ciclo: **0,005091574 USD**; budget totale: **10 USD**;
  residuo **9,994908426 USD**, senza prenotazioni pendenti o costi sconosciuti.

Gli snapshot storici delle fonti non dimostrano che quei record siano immutati
oggi: durante i due round confrontare versioni/date e annotare eventuali variazioni
delle fonti, separandole dalle regressioni del codice.

## Passaggio operativo

Il [runbook](../runbooks/operational-consolidation.md) contiene migrazione,
configurazione development, revisione di web/worker, creazione dell'unica campagna,
comandi del client, arresto e conservazione del ledger. Il primo deploy del codice
è attivo; seguono configurazione QA, verifica concorrente sul database distribuito
e baseline 10×3.
Solo dopo l'archiviazione della baseline si iniziano i quattro contratti interni
e gli altri interventi del piano. Il confronto finale e l'aggiornamento della
relazione PDF/Word richiedono i risultati del candidato e non sono ancora attestati.

## Aggiornamento del 3 novembre: preparazione del gate live

Il controllo su Laravel Cloud e il preflight applicativo del 02/11 alle
23:06 UTC hanno confermato il deploy `7852a14`, precedente alla strumentazione:
classe del ledger e tabelle QA assenti, `APP_RELEASE` nullo. Il token esistente
è valido e ha accesso diagnostico; non è stato creato o ampliato alcun accesso.
La configurazione effettiva di `autry` è archiviata privatamente. Quel controllo
precede il primo deploy e non descrive lo stato distribuito successivo.

La preparazione indipendente degli esiti ora comprende:

- 34 preview complete, tutte non canoniche, ricostruite dai chunk tramite API;
- confronto indipendente dei 34 file originali nello storage: tutti i 55 estratti
  selezionati corrispondono dopo normalizzazione degli spazi; un PDF è stato
  verificato tramite estrazione del testo, conservando l'hash dei byte originali;
- 55 fatti con quote letterali, riferimenti e hash del contenuto;
- 12 attese su record ERP, separate dai fatti delle email e dagli stati di reso;
- distinzione tra `charge` dell'ordine e prova di un rimborso;
- aggiornamenti temporali, negazioni e richieste registrati separatamente;
- un caso senza riscontro ERP storico, da verificare sul nuovo recupero live.

Le schede in `oracle-review.json` sono state riesaminate rispetto agli originali,
senza usare le risposte storiche come verità. Hash della versione delle attese:
`86fddfc9766b52737952140e36400139da80e1666cc6c6593746ce7f169b6a2d`.
Questa è una revisione delle fonti archiviate, non una baseline eseguita e non
una prova di assenza di documenti ulteriori.

Il catalogo reale ha evidenziato fasce di prezzo condizionali. Il commit
`17f3a4d6c` prenota il massimo tra endpoint, fasce di contesto, finestre orarie e
cache write, senza sconti presunti; verifica gli identificativi canonici nel
catalogo e disabilita la ricerca web implicita. I prezzi dei quattro modelli
effettivi sono stati letti con sole GET. Un errore DNS temporaneo ha impedito
un primo tentativo, conservato come incompleto. La lettura successiva del
03/11 alle 00:00 UTC ha verificato prezzi e identificativi canonici e prodotto
il manifesto privato con hash
`c1df069f547ad2c41cbe940252c207bc0699611c3495cd3f4803faf443bab445`.
Nessun dato mancante è stato sostituito con un prezzo di default o con un modello
differente.

Il nuovo `qa:campaign probe` rende eseguibile G0b sul database configurato:
sincronizza due processi PHP applicativi, verifica una sola prenotazione ammessa
e controlla una doppia callback. Il contesto è simulato, non possiede un attore
chat, non apre conversazioni e blocca richieste HTTP. Saldi e riferimenti di
simulazione restano distinguibili da quelli dell'unica campagna AI da 10 USD.
La prova passa localmente su SQLite; la verifica PostgreSQL attende l'attivazione
della configurazione QA.

Il deploy Cloud **183**, revisione `01092f5122ceb18467cf01d5ffbfcd189d13cff9`,
è stato verificato come attivo: classi e tabelle QA sono presenti. Il preflight
successivo al deploy rileva ancora `APP_RELEASE=null`, campagne disabilitate e
tier `production`; la creazione della campagna viene quindi correttamente
rifiutata. L'inserimento delle tre variabili richiede l'editor Cloud: quello
mascherato non accetta modifiche. La revisione automatica ha rifiutato l'azione
di rivelazione dei segreti; è stata chiesta un'autorizzazione specifica senza
aggirare il blocco o alterare la configurazione tramite la console applicativa.

Verifiche aggiornate: suite estesa di 645 test, 644 superati e lo stesso singolo
fallimento Flow già presente; QA mirata dopo l'aggiunta degli alias: 21 test e
96 asserzioni superati; 16 test Python superati. Il runbook ora segue il ciclo
gestito da Laravel Cloud per migrazioni, configurazione e ricambio dei worker.

## Attivazione e primo turno del 3 novembre

Dopo l'autorizzazione esplicita a utilizzare l'editor Cloud, sono state aggiunte
le sole tre variabili QA. Il deploy di configurazione **184**, sullo stesso SHA
`01092f5122ceb18467cf01d5ffbfcd189d13cff9`, è concluso. Il preflight conferma
campagne abilitate, tier `development` e `APP_RELEASE` corretto; nessuna
credenziale è stata riportata. Il runtime e la prima run confermano la stessa
revisione nel processo web e nel worker che ha eseguito il turno.

G0b passa sul database PostgreSQL: due processi applicativi concorrenti producono
`blocked` e `reserved`; la doppia callback lascia spesa e prenotazioni a zero.
L'evidenza simulata resta separata dalla campagna di inferenze. Modelli, soglie e
limiti effettivi coincidono con il preflight precedente.

Il primo turno baseline è fallito. Due inferenze risultano registrate: una
comprensione della domanda e un embedding, con costo totale **0,005091574 USD**.
La risposta embedding riporta il nome nativo `text-embedding-3-small`; la GET
al registro della medesima generazione conferma `openai/text-embedding-3-small`
e lo stesso addebito di 320 nanodollari. Il confronto con il manifesto ha fermato
la campagna su `qa_response_model_mismatch`. Il fallimento e il costo sono
conservati; non è stata creata una campagna sostitutiva o azzerato alcun saldo.

La correzione QA locale verifica questa specifica forma nativa con una GET,
richiedendo concordanza di ID generazione, tipo embedding, modello e costo.
Conserva modello nativo e prova tecnica separati. Il comando
`reconcile-embedding-identity` può riaprire la stessa campagna soltanto dopo
riscontro verificato e in assenza di run aperte, costi ignoti, prenotazioni
pendenti, errori diversi o limiti esauriti. Un evento conserva la causa originale
dell'arresto e tutti i saldi; il tentativo mantiene costo, errore e tempi originali.
Questa correzione richiede integrazione e deploy prima di riprendere il collaudo.
La suite mirata QA e isolamento tenant supera **32 test e 171 asserzioni**,
con HTTP simulato; include riscontro mancante o discordante, cambiamento
concorrente, attore revocato e rifiuto della riapertura con addebiti irrisolti.
Il fix è nel commit locale `42ba10617`; il commit `df1ec403f` consente di numerare
turni ulteriori nella stessa campagna, per rispettare il requisito di almeno tre
turni senza sostituire il primo fallito. La suite estesa aggiornata conferma lo
stesso solo errore Flow già presente; 17 test Python sono superati.

G0c resta incompleto. Le fasi 1–6 mantengono il loro perimetro completo e attendono
la baseline obbligatoria, senza anticipare modifiche della pipeline.


## Revisione e pubblicazione del branch — 3 novembre

Marco ha autorizzato push, merge del branch in `develop` e deploy di sviluppo.
Il branch remoto è pubblicato fino a `b918d4625`. La PR e il merge attendono
la conclusione dei gate completi.

Il gate Flow ora verifica il confine definito da ADR 0032 e una vera esecuzione
con job accodati: tenant corretto, payload cifrati, riferimenti opachi e assenza
di righe nelle tre tabelle non adottate. Non cambia il runtime della pipeline.
La suite mirata supera 15 test e 122 asserzioni, con uno smoke live opzionale
intenzionalmente escluso.

Le revisioni hanno consolidato l'ammissione delle campagne: contesto immutabile,
turni monotoni sotto lock, primo turno in una conversazione vuota, attore
riautorizzato prima dell'esecuzione e stati terminali condivisi. La revisione
locale del delta QA riporta zero must-fix. Il controllo UI del modulo Eval
verifica ora anche schema e versione del payload, non solo il codice HTTP.

I gate aggiornati includono **2.011 test frontend e 34 test legacy superati**,
build e typecheck riusciti. Il gruppo PHP precedente su fixture offline, interpretazione, claims atomici
e pulizia OCR supera 62 test e 338 asserzioni; l'ultimo delta delle fixture
finite supera **20 test e 62 asserzioni**, comprese le esclusioni dei metadati.
La suite PHP completa precedente arriva a 6.520 test, con una sola asserzione
errata che assumeva vuota la directory temporanea condivisa: il test ora misura
l'assenza di nuovi file. Una ripetizione da 6.530 test ha sovrapposto una vecchia classe di test già
caricata e il nuovo driver delle fixture: quell'esito misto non è valido come
gate finale. La suite viene ripetuta su `b918d4625`, con hash congelati dei
2.523 input PHP/config/composer e progressione per classe.

Playwright usa due siti Herd isolati, `askmydocsconsolidationqa.test` e
`askmydocsconsolidationqa-outbound.test`, con il solo database usa e getta
`askmydocs_consolidation_test`. Il sito principale e il suo database restano
inalterati. Le fixture usano provider fittizi senza chiavi AI, interpretazione
validata e decisioni finite abilitate soltanto in ambiente `testing` con opt-in.
Domande, decisioni o dati fuori fixture non vengono approvati. I controlli host
di accesso, identità, hash, supporto letterale e pertinenza restano attivi.
Questi esiti non validano Jev o la qualità semantica delle risposte live.

Il browser ha confermato il recupero paginato dei tre ordini sintetici con i
valori originali. Sono stati corretti la navigazione CodeMirror e diversi test
che usavano hub precedenti, tenant impliciti, sessioni invalidate o l'id della
versione documentale archiviata. Il gruppo mirato ha superato 40/41 scenari; la sola richiesta connettori priva
di tenant è stata corretta e il gruppo connettori ha poi superato 10/10 scenari
(inclusi quattro setup). La prima suite generale è stata interrotta dopo l'emersione di fixture
obsolete e dell'overlay developer di Herd che intercettava i click. Il browser
di test usa ora il marker preview riconosciuto da Herd soltanto sui domini
`.test`; il browser CI rimane invariato. Il gruppo chat/widget documentale
aggiornato supera **11/11 scenari**, inclusi i setup: il profilo aziendale viene
configurato via API senza fatti attesi, con progetto esplicito. La run sintetica
finale è `grounded` e `complete`. La suite generale da 389 scenari è ripartita
sul candidato congelato.
La revisione indipendente del nuovo delta non ha must-fix residui.

Una revisione Copilot locale è stata inizialmente rifiutata dal controllo
automatico per l'invio di sorgenti presunti privati. Il connettore GitHub ha poi
confermato che il repository è pubblico. Dopo il push autorizzato, una nuova
revisione legge esclusivamente un archivio Git del commit remoto pubblico
`c1a3d6a98`, senza overlay locale o file privati: il controllo l'ha consentita.
La revisione del delta finale pubblico `b918d4625` riporta **0 must-fix e 0 nit**;
anche la revisione indipendente locale non ha must-fix. Le sessioni CLI sono
limitate a 30 crediti ciascuna. Il consumo locale misurato cumulativo è
15,0549765 crediti, pari a 0,150549765 USD nominali al listino GitHub, più
0,85 crediti osservati soltanto arrotondati in un precedente prompt di uso.
La copertura da abbonamento rimane distinta dall'equivalente nominale.

La campagna live originaria resta arrestata con **0,005091574 USD** contabilizzati
e nessuna prenotazione aperta. Si prevede di riservare 1 USD alle revisioni e di
ridurre a 9 USD il tetto live, con audit e senza alterare costi o risultati già
registrati; la riduzione non è ancora applicata. Nessun test simulato viene
contato come turno baseline. G0c e le fasi 1–6 restano da completare.
