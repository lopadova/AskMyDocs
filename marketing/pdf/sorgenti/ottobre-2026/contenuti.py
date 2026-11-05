"""Sorgente editoriale del report; dati e fonti restano separati dal layout."""

TITLE = "Ottobre. Il mese delle connessioni."
SUBTITLE = "AskMyDocs | Diario di prodotto | 1-29 ottobre 2026"
SNAPSHOT = "8003ca793c0218ec13451b449fa02a874de762a0"
BASE = "cd1e917f7b37aed43ad33881e7d8146f81748700"


def block(title, text):
    return {"title": title, "text": text}


ARTICLES = [
    {
        "kicker": "01 / INTELLIGENZA DI RICERCA", "title": "Una regia unica.\nPiù fonti al lavoro.",
        "deck": "Documenti, API e connessioni MCP entrano nello stesso ciclo di ricerca. Le attività indipendenti possono procedere insieme.",
        "left": [
            block("La domanda diventa un piano", "Il cambiamento più profondo arriva il 28 e 29 ottobre: la ricerca viene coordinata per ondate. Un unico planner decide quali fonti interrogare, aspetta gli esiti delle attività avviate e sceglie se rispondere oppure cercare ancora. Le dipendenze vengono rispettate: una chiamata che richiede un identificativo appena scoperto parte nell'ondata successiva."),
            block("Parallelismo con confini precisi", "Le attività pronte vengono eseguite in processi PHP isolati. Documenti, route API e MCP condividono budget logici, richieste fisiche e scadenze. Gli esiti vengono riuniti nel processo principale; il fallimento di una fonte conserva i risultati utili delle altre. Le continuazioni dei tool asincroni hanno checkpoint e protezioni contro callback duplicate."),
        ],
        "right": [
            block("La ricerca esatta torna in primo piano", "I worker documentali combinano identificativi letterali e ricerca semantica. I candidati esatti precedono i risultati semantici e vengono aperti attraverso il lettore che applica permessi e verifica delle fonti. Un errore parziale di lookup viene riportato al coordinatore senza cancellare le letture riuscite."),
            block("Il tempo per rispondere viene protetto", "Interpretazione e prima proposta di piano possono condividere una chiamata AI. Prima di pianificare e prima di avviare l'ondata, il sistema verifica che resti un intervallo utile di esecuzione e una riserva per la risposta. I task scaduti vengono fermati sul processo effettivo; le prove già raccolte restano disponibili per una risposta parziale esplicita."),
        ],
        "callout": "Cosa cambia: una domanda che attraversa archivio e sistemi live dispone di una regia comune, con risultati parziali conservati e limiti osservabili.",
        "sources": ["S02", "S03"],
    },
    {
        "kicker": "02 / QUALITÀ DELLA RISPOSTA", "title": "Ogni affermazione\nritrova la sua fonte.",
        "deck": "Gran parte delle 131 correzioni riguarda il rapporto fra domanda, prove raccolte e testo finale.",
        "left": [
            block("La pertinenza viene prima della prosa", "Le correzioni del mese distinguono la rilevanza di un fatto dalla completezza della risposta. Il sistema conserva il soggetto richiesto, la cronologia e la voce di chi parla; controlla separatamente gli aspetti di una domanda composta. I confronti fra casi restano attività distinte. Saluti, riepiloghi e richieste sulle capacità dell'assistente hanno un'interpretazione dedicata."),
            block("Il documento originale resta il riferimento", "Il lettore recupera passaggi più completi, titoli e identificativi letterali. Per le email sottoposte a triage, riapre l'originale verificato tramite hash. Una scheda generata o un tag automatico aiutano a trovare la fonte, ma non diventano parole del mittente. Vengono corretti anche riferimenti brevi, citazioni, etichette numeriche e finestre di tabelle lunghe."),
        ],
        "right": [
            block("Anche i dati live hanno un'identità", "Le risposte API mantengono identità dei record, alias dichiarati dallo schema e orario dell'osservazione. Gli identificativi devono derivare da fonti autorizzate; valori inventati o incompatibili vengono respinti prima della chiamata. Errori di trasporto e coperture incomplete restano espliciti nella risposta, senza diventare prove dell'assenza di un record."),
            block("Modelli e istruzioni sotto controllo", "Le istruzioni persistenti del tenant attraversano la ricerca. Sintesi e riparazione hanno impostazioni dedicate; il profilo del progetto può governare tutte le fasi testuali della run. Embedding e Jev restano separati. Il routing economy rispetta modello e limiti configurati. Il 27 ottobre aggiunge configurazione guidata con proposte AI da rivedere e cataloghi live dei modelli, con prezzi, fonte, data e gestione dei dati obsoleti."),
        ],
        "callout": "La riscrittura editoriale dei fatti viene ricontrollata. Un testo più leggibile conserva soggetti, attribuzioni e limiti delle prove disponibili.",
        "sources": ["S04", "S05", "S06", "S25"],
    },
    {
        "kicker": "03 / EMAIL E INGESTIONE", "title": "La posta entra\ncon un criterio.",
        "deck": "Un modulo di triage comune collega IMAP, Gmail e Microsoft Mail: regole versionate, decisioni durevoli e campagne sullo storico.",
        "left": [
            block("Prima il download, poi la decisione", "Il nuovo triage usa Jev per riconoscere caratteristiche configurate: tag sì/no, categorie esclusive e livelli ordinati. Il corpo della mail attende la relativa ricevuta durevole di ingestione; gli allegati proseguono sul proprio percorso. I worker di download non fanno chiamate decisionali. Ogni salvataggio delle regole crea una revisione immutabile."),
            block("Le regole si possono spiegare a parole", "L'assistente prepara una bozza da un prompt, con esempi fittizi, etichette e criteri modificabili. Applicare la bozza, salvare le regole e attivare il triage sono azioni distinte. La funzione è inizialmente spenta per ogni account. Gli esiti incerti restano non determinabili; un errore del provider non viene classificato come risposta negativa."),
        ],
        "right": [
            block("Lo storico diventa un lavoro riprendibile", "Le campagne selezionano account, progetto, cartella, date e stato. Fissano revisione del profilo e limite dei documenti, espongono pause, riprese e retry per elemento. Snapshot e impronte delle fonti consentono di riusare decisioni già salvate. Il riepilogo distingue copertura, costi osservati e stime disponibili, senza avviare automaticamente nuove analisi a pagamento quando cambiano le regole."),
            block("IMAP riceve cure operative", "Accesso in sola lettura e download peek, correzioni dell'identità Microsoft, lotti limitati e checkpoint migliorano l'importazione. Vengono ridotte letture ridondanti e parsing di messaggi troppo grandi. La ricerca incrementale degli UID passa al server; i sync bloccati cedono prima del timeout. L'import storico si può cancellare conservando le mail già salvate, con errori e fasi di download più visibili."),
        ],
        "callout": "Gli originali restano separati dalle classificazioni automatiche. L'attivazione del triage e delle campagne resta una scelta esplicita dell'amministratore.",
        "sources": ["S07", "S08", "S09"],
    },
    {
        "kicker": "04 / I NUOVI PACCHETTI EMAIL", "title": "Gmail e Outlook.\nDue ingressi nativi.",
        "deck": "I due connettori nascono il 14-16 ottobre e vengono inclusi in develop come dipendenze Composer v1.0.0.",
        "left": [
            block("Gmail API", "Il pacchetto padosoft/askmydocs-connector-gmail usa le API Gmail con OAuth, scope di sola lettura, accesso offline e gestione del refresh. Comprende selezione delle etichette, filtri, decodifica MIME, allegati e sincronizzazione riprendibile fra scansione iniziale e history. Un cursore scaduto avvia il percorso di recupero previsto."),
            block("Una mail, un'identità stabile", "Corpo e allegati condividono una chiave composta da tenant, installazione, provider, mailbox e ID nativo. Nomi uguali degli allegati restano distinguibili. Un manifesto ordinato espone anche i file esclusi dalla policy. Il checkpoint avanza solo dopo i passaggi durevoli di consegna. La revisione locale documenta 91 test e 405 asserzioni per ciascuno dei due ambienti Laravel collaudati."),
        ],
        "right": [
            block("Microsoft Graph Mail", "Il pacchetto padosoft/askmydocs-connector-microsoft-mail usa Graph v1.0. Copre mailbox personali Outlook.com/Microsoft 365 e target condivisi espliciti con i permessi delegati richiesti. Discovery ricorsiva delle cartelle, ImmutableId, baseline e delta per cartella mantengono continuità anche con rinomine e spostamenti."),
            block("Lo stesso contratto verso AskMyDocs", "Entrambi emettono l'evento sincrono email v1 prima di salvare o inoltrare le fonti. L'host controlla manifesto, revisione e ricevute, poi applica triage, ACL e indicizzazione. Microsoft documenta 95 test e 893 asserzioni per ciascuna delle quattro combinazioni PHP/Laravel. Le suite usano dati sintetici: consenso OAuth e accesso a mailbox reali restano verifiche separate."),
        ],
        "callout": "Nomi da riconoscere: i repository sono gmail-api e outlook-api; i nomi Composer e le chiavi di connessione restano gmail e microsoft-mail.",
        "sources": ["S08", "S10", "S11", "S12", "S13", "S26"],
    },
    {
        "kicker": "05 / HELPDESK", "title": "Dai ticket\nalla memoria dei casi.",
        "deck": "Freshdesk entra nel prodotto. Zendesk nasce come pacchetto autonomo. Le integrazioni distinguono consultazione live e archivio indicizzato.",
        "left": [
            block("Freshdesk: il primo ingresso", "Il nuovo connettore importa ticket, conversazioni, note private consentite, articoli pubblicati e allegati compatibili. Ha checkpoint, impronte, lock, rate limit e recupero storico. Nove strumenti di lettura permettono di cercare ticket, contatti, agenti e articoli, aprire dettagli e conversazioni. L'host integra importazioni e tool autenticati; il lockfile fissa la versione 1.0.0."),
            block("Il lavoro successivo del pacchetto", "Il checkout locale aggiunge profilo d'uso per l'agente, guardia GET, schede problema/causa/intervento/esito, interruttori per installazione e data di inizio dello storico. La scheda collega i fatti alle citazioni e mantiene espliciti esiti mancanti o conflittuali. La coda dedicata e le schermate host ricevono miglioramenti operativi durante il mese."),
        ],
        "right": [
            block("Zendesk: una nuova base autonoma", "Il 12 ottobre nasce il pacchetto Zendesk con ingestione riprendibile, ticket, commenti, allegati e articoli pubblici, oltre a nove tool live. Distingue richiedenti e assegnatari, riporta paginazione e copertura e predispone il contratto per le schede dei casi. Il trasporto è di sola lettura. Il registro locale riporta 87 test riusciti e un test live saltato."),
            block("Il confine di consegna conta", "Zendesk non compare nel composer.lock di develop e il repository remoto interrogato non è accessibile dalla connessione corrente: la prova disponibile è locale. Anche per Freshdesk, le estensioni successive alla revisione bloccata nel lockfile richiedono un allineamento delle dipendenze prima di certificare quel comportamento nell'host. Codice del pacchetto, integrazione e release vengono quindi riportati separatamente."),
        ],
        "callout": "Un ticket chiuso non dimostra da solo un esito positivo. Le schede dei casi mantengono il collegamento con ciò che le fonti documentano.",
        "sources": ["S14", "S15", "S16", "S26"],
    },
    {
        "kicker": "06 / CONNETTORI API", "title": "Configurazioni\nche possono viaggiare.",
        "deck": "Il mese porta import/export completi, pacchetti JSON e ZIP e una lettura più precisa delle API esistenti.",
        "left": [
            block("Dalla configurazione al pacchetto", "Il 14 ottobre arrivano esportazione e importazione complete; il 21 il formato diventa un pacchetto portabile. JSON v1 e ZIP condividono lo stesso connector.json, con README facoltativo e inerte. La UI espone Import API ed Export API, anteprima e validazione. L'import crea una copia nel tenant corrente attraverso il percorso transazionale esistente."),
            block("I segreti restano una configurazione locale", "Credenziali e header non pubblici devono essere reinseriti. Riferimenti, struttura e versione vengono controllati; i file ZIP non vengono estratti o eseguiti. Dimensioni, percorsi, duplicati, compressione e CRC hanno limiti espliciti. Importare la configurazione non esegue chiamate HTTP e non dimostra che endpoint o autenticazione siano funzionanti."),
        ],
        "right": [
            block("Più precisione nell'editor", "L'editor di route riceve controlli più chiari su posizione dei parametri e diagnostica. Arrivano User-Agent selezionabili e header condivisi per le regole Cloudflare. I profili di autenticazione salvati tornano negli elenchi del pacchetto API. Il consumer passa da beta.1 a beta.5: alcune modifiche della libreria precedono ottobre, mentre l'adozione nell'app avviene nel mese."),
            block("Le API legacy conservano il loro significato", "Le relazioni lista/dettaglio usano campi osservati nelle risposte riuscite, con path, tipo e maiuscole originali. I nuovi identity fields dichiarano l'identità del record. Vengono gestiti XML legacy esplicitamente configurati, errori XML e ordinamento GESCAT. I riferimenti nella risposta aprono l'ispezione della chiamata API, collegando il dato al suo contesto di esecuzione."),
        ],
        "callout": "Il pacchetto portabile trasferisce configurazioni. Autorizzazione, attivazione e verifica dei sistemi destinatari restano passaggi dell'ambiente che lo riceve.",
        "sources": ["S17", "S18", "S30", "S26"],
    },
    {
        "kicker": "07 / TOOL COMPOSTI", "title": "Una sequenza API\ndiventa uno strumento.",
        "deck": "Il 26 ottobre entra in develop il nucleo dei tool API composti: definizioni tipizzate, verifiche, grafi in coda e un editor amministrativo.",
        "left": [
            block("Il percorso viene dichiarato", "Input pubblici, mapping e trasformazioni descrivono come i dati passano fra le chiamate. Le operazioni deterministiche comprendono anche join per chiave. Il grafo esplicita dipendenze e rami indipendenti; il runtime riusa Laravel Flow per eseguirli con code, limiti e checkpoint. I passaggi già completati vengono riutilizzati durante il recupero."),
            block("Pubblicare una revisione verificata", "Lo strumento ha revisioni, hash del contenuto, cronologia delle verifiche e un ciclo di compatibilità. L'amministratore può creare una definizione, ottenere una bozza AI, validarla ed eseguire prove simulate e live prima di pubblicarla. Diagnostica per chiamata e stato asincrono rendono leggibile la run. I controlli mantengono tenant, progetto e autorizzazioni dell'attore."),
        ],
        "right": [
            block("Un innesto nel lavoro dell'agente", "Il catalogo rende disponibili i tool ammessi al progetto; le esecuzioni lunghe restituiscono il controllo attraverso continuazioni durevoli. Budget di richieste, deadline e cancellazione vengono condivisi con l'agente. Le misure opzionali raccolgono token riportati dal provider e tempi, senza salvare prompt o risposte, per confrontare run equivalenti."),
            block("Disponibilità e misura", "Il nucleo è integrato, ma API_TOOLS_ENABLED è false per impostazione predefinita. Servono migrazioni, worker e attivazione nell'ambiente previsto. La prima consegna è di sola lettura. Le prove di rete documentano equivalenza degli output e concorrenza; non provano un risparmio di token o una minore latenza di produzione. L'estensione per configurare e approvare tool dalla chat resta su un ramo separato."),
        ],
        "callout": "Valore del mese: rendere una procedura ripetibile, ispezionabile e versionata. I vantaggi economici vanno misurati su esecuzioni confrontabili.",
        "sources": ["S18", "S19", "S32"],
    },
    {
        "kicker": "08 / ACCESSO DALL'ESTERNO", "title": "La conoscenza\nesce dal suo perimetro.",
        "deck": "OAuth e MCP aprono percorsi di connessione a Codex, Claude e Cursor, mantenendo il legame con il progetto autorizzato.",
        "left": [
            block("Consenso e connessioni gestibili", "Il mese si apre con grant Authorization Code e PKCE, schermata di consenso e gestione delle applicazioni collegate. Il 21 ottobre arriva il collegamento MCP a una specifica azienda e a un progetto scelto dall'utente. Token, membership e permessi correnti vengono ricontrollati. Refresh e revoca appartengono alla stessa famiglia di autorizzazione."),
            block("Un percorso guidato per i client", "La configurazione accompagna Codex, Claude e Cursor e genera archivi plugin caricabili. I callback desktop accettano i loopback esatti previsti dal contratto. La gestione token viene ridisegnata con creazione guidata; l'amministrazione del server mostra anche le connessioni OAuth. Il server MCP entra nelle dipendenze di produzione."),
        ],
        "right": [
            block("Anche i documenti possono arrivare dal client", "L'import MCP prepara file privati in staging e presenta all'utente il set esatto da approvare nel browser. La successiva ingestione riusa conversione, gestione PII e pipeline documentale dell'host. Scope, ruolo, appartenenza al progetto, hash e scadenza vengono verificati nei diversi passaggi; l'approvazione rimane un'azione della sessione utente."),
            block("PactAuth prepara il terreno", "Su develop è presente l'autorizzazione vincolata al progetto per il client Sorgente. Il trasporto PACT di quel ramo risponde ancora con indisponibilità esplicita: il consenso OAuth non basta a dimostrare una delega operativa. Il simulatore A2A/PACT realizzato in un altro ramo è riportato separatamente. La generazione di un plugin locale, inoltre, non equivale alla sua pubblicazione in una directory."),
        ],
        "callout": "L'unità di accesso è il progetto autorizzato. Il percorso di importazione aggiunge una conferma sul contenuto concreto che verrà indicizzato.",
        "sources": ["S20", "S21", "S22"],
    },
    {
        "kicker": "09 / ESPERIENZA EDITORIALE", "title": "Un'identità\nche attraversa l'app.",
        "deck": "Il 16 ottobre ridisegna superfici, navigazione e paesaggi visivi. Il 27 allinea il marchio del sito all'identità dell'app.",
        "left": [
            block("Spazi più coerenti", "Workspace e amministrazione ricevono una navigazione organizzata, una sidebar comprimibile e layout editoriali responsive. I controlli Select condivisi vengono adottati nelle schermate; lingua e landing si allineano. Arrivano cataloghi di interfaccia italiano/inglese, monogrammi sobri e finestre che richiedono una chiusura esplicita. Popover delle fonti e notifiche vengono riposizionati entro lo spazio utile."),
            block("Il marchio trova una forma comune", "Il nuovo wordmark AskMyDocs unisce il simbolo neutro dell'app a un lettering serif. Header e footer pubblici, sidebar e autenticazione usano le varianti chiare e scure. La documentazione registra verifiche responsive fino al viewport di 320 pixel e corregge la capitalizzazione del nome."),
        ],
        "right": [
            block("Giorno, notte e mondi dell'installazione", "Le illustrazioni seguono la modalità chiara o scura. Alle quattro collezioni editoriali si aggiungono tredici temi: dieci mondi e tre interpretazioni astratte, selezionabili nella gestione dell'aspetto da parte dei superadmin. Cartografia, botanica, paesaggi polari, archivio e orbite semantiche costruiscono un repertorio coerente di scene."),
            block("Un'immagine spiega il tono", "Il cambiamento visivo rende riconoscibili gli ambienti di conoscenza, archivio e comunità. Le illustrazioni qui riprodotte provengono dagli asset del prodotto e hanno funzione editoriale: non rappresentano schermate o prove di un flusso. Le modifiche di stile distribuite nei pacchetti connettori restano distinte dalle versioni effettivamente risolte dal consumer."),
        ],
        "callout": "La stessa direzione visiva raggiunge landing, lavoro quotidiano e client desktop: carta calda, verde profondo e gerarchie tipografiche leggibili.",
        "sources": ["S23", "S24", "S25"], "image_strip": True,
    },
    {
        "kicker": "10 / CONVERSAZIONI", "title": "La ricerca continua.\nIl lavoro anche.",
        "deck": "Sessioni in background, risultati progressivi verificati e riferimenti ispezionabili rendono più leggibile ciò che l'assistente sta facendo.",
        "left": [
            block("Si può cambiare sessione", "Dal 21 ottobre le ricerche delle sessioni possono continuare in background, con indicatori di risposte non lette. Recupero delle run e ricevute di lettura persistono attraverso i riavvii. La cronologia riceve un pannello ridimensionabile e controlli di sessione corretti. Le domande successive conservano meglio intenzione operativa e riferimenti al contesto."),
            block("I risultati arrivano prima della chiusura", "Il 28 ottobre introduce la pubblicazione durevole di risultati intermedi verificati sulle fonti. La chat li mostra mentre la ricerca prosegue. Le prove già approvate vengono conservate anche quando la finalizzazione esaurisce il tempo. Il sistema mantiene distinti avanzamento, risposta parziale e conclusione della run."),
        ],
        "right": [
            block("Le fonti diventano esplorabili", "La risposta consente di ispezionare la chiamata API da cui nasce un riferimento. Citazioni, titoli email e campi numerici ricevono correzioni di presentazione. Il passaggio fra documenti indicizzati e dati live risulta più trasparente, mentre l'accesso viene ricontrollato sui contenuti autorizzati."),
            block("La voce segue il lavoro reale", "La conversazione realtime descrive l'avanzamento della ricerca con frasi di attesa variate e sobrie, solo dopo conferma del server. Sono corretti handshake, budget di esecuzione inline e rilascio dei turni terminati. I risultati tool già salvati vengono recuperati dopo un gateway timeout. Un aggiornamento locale separato del bridge ElevenLabs a v4 Turbo non modifica la revisione bloccata nel consumer."),
        ],
        "callout": "Cosa cambia nella giornata: seguire più sessioni, tornare su una ricerca e aprire la prova collegata richiede meno ricostruzione del contesto.",
        "sources": ["S03", "S30", "S31", "S33"],
    },
    {
        "kicker": "11 / CLIENT NATIVI", "title": "Lo stesso linguaggio,\noltre il browser.",
        "deck": "Il client Tauri rinnova workspace, chat, ricerca e lettura delle fonti. Il lavoro Apple aggiorna icona e schede prodotto, con limiti di distribuzione documentati.",
        "left": [
            block("Il desktop ritrova il prodotto", "Il refresh del 27 ottobre porta palette editoriale, gerarchie serif, pulsanti condivisi con il web e icone SVG coerenti. Le schermate di login, registrazione, scelta workspace, sessioni, Search e Knowledge vengono aggiornate insieme al dialogo documentale. La verifica registra dieci test desktop, controlli sui pulsanti, build e avvio del bundle macOS."),
            block("Confini funzionali dichiarati", "La gestione delle sessioni del desktop rimane locale e separata per tenant/progetto. Il refresh non introduce sincronizzazione delle cartelle e conversazioni web né le sessioni agentiche e vocali del web. Le prove browser con fixture non dimostrano un'integrazione autenticata completa con il backend di produzione."),
        ],
        "right": [
            block("Apple: progressi concreti", "Vengono preparati l'identità neutra a due pannelli, le icone di piattaforma e i metadati inglesi di AskMyDocs - AI Hub. Il registro riporta il salvataggio delle schede iOS/macOS e di uno screenshot nativo. La build di sviluppo iOS compila dopo gli adeguamenti SwiftPM e target minimo iOS 15; il login viene osservato su due simulatori iPhone."),
            block("La nuova beta resta da completare", "Lo stato del 27 ottobre segnala un vecchio build TestFlight in Missing Compliance. La nuova icona non è stata caricata con un nuovo build. Restano verifica mobile completa, accessibilità e tastiera, accordi Apple e dichiarazioni di servizio da completare. Non è quindi documentato un nuovo rilascio TestFlight pronto per i tester. Questa analisi conserva la distinzione fra compilazione, schermata osservata e distribuzione."),
        ],
        "callout": "La maturazione del client è visibile. La distribuzione iOS richiede ancora i passaggi registrati nel suo stato di consegna.",
        "sources": ["S34", "S35"],
    },
    {
        "kicker": "12 / PRESTAZIONI E OPERATIVITÀ", "title": "Meno peso.\nPiù tracce utili.",
        "deck": "Ottimizzazioni del retrieval, diagnostica durevole e correzioni della coda affrontano problemi diversi, con misure separate.",
        "left": [
            block("Il retrieval trasporta ciò che serve", "Il 22 ottobre corregge scansioni filtrate HNSW, lookup letterale e ranking. La proiezione dei candidati evita di riportare i vettori embedding già usati da SQL. Nel campione locale K=24 i valori PDO scendono da 485.772 a 22.892 byte, a parità di ordine, punteggi e campi utili. La riduzione del 95,3% riguarda quella proiezione, non il traffico totale o il tempo di risposta dell'app."),
            block("La cache scrive in gruppo", "Gli aggiornamenti LRU della cache embedding vengono aggregati. Nel caso sintetico documentato, due testi distinti già in cache richiedono un UPDATE invece di due; a tempo congelato la seconda chiamata non scrive. Gli strumenti locali aggiungono snapshot limitati, piani sanitizzati e prove di concorrenza riproducibili, senza chiamate AI."),
        ],
        "right": [
            block("Le run lasciano una diagnosi", "Span persistenti ed export di sessione mostrano fasi, attese e fallimenti. I payload annidati vengono ripuliti dalle credenziali riconoscibili. La finalizzazione gestisce errori di coda e timeout; le riserve temporali proteggono sintesi e risultati già verificati. L'obiettivo operativo è distinguere attesa in coda, tempo del modello, retrieval e tool."),
            block("Cloud: un minimo di un worker", "Il registro del 29 ottobre documenta una correzione applicata alla coda interattiva: minimo da zero a un worker, massimo invariato a 25. Il deployment è verificato sulla revisione e4c4a2610. Tre probe terminali successivi, senza AI o tool, registrano attese di 439, 66 e 60 ms. Dimostrano quelle consegne consecutive; non misurano la latenza completa della chat e non certificano l'ultimo commit di develop in produzione."),
        ],
        "callout": "Le prestazioni vengono raccontate alla scala della prova: query locale, consegna del job, singola fase o run completa. Le percentuali non sono intercambiabili.",
        "sources": ["S36", "S37", "S02"],
    },
    {
        "kicker": "13 / VERIFICA", "title": "La qualità lascia\nuna ricevuta.",
        "deck": "Le consegne del mese portano suite mirate, fixture riproducibili, prove di processo e registri che espongono anche i controlli incompleti.",
        "left": [
            block("Nuovi strumenti per riprodurre i problemi", "Le probe KB registrano revisione del codice, configurazione dei ruoli/modelli, fonti originali e risposte provider utili alla diagnosi. Le verifiche indipendenti trattano email e osservazioni API congelate, rispettando orari, campi richiesti e limiti delle fonti. Un guard locale opzionale contabilizza l'inferenza e il relativo budget."),
            block("Test sulla vita dei processi", "Le ondate di ricerca hanno regressioni con veri sottoprocessi PHP, deadline cumulative e isolamento di errori e timeout. Le prove del worker persistente attraversano una prima risposta e due turni successivi, verificando rilascio dei lock e ripristino di tenant, autenticazione, lingua, fuso e scope dei modelli. HTTP e modelli restano fixture nei test applicativi."),
        ],
        "right": [
            block("Alcuni esiti storici documentati", "Gmail: 91 test per ambiente; Microsoft Mail: 95 per combinazione; API tools: 229 casi e 1.945 asserzioni nella suite estesa, più prove di rete separate. Il registro Cloud riporta 471 test e 2.644 asserzioni prima del deployment infrastrutturale. Questi insiemi si sovrappongono, si riferiscono a revisioni diverse e non si sommano in una copertura complessiva."),
            block("Anche i limiti fanno parte del risultato", "Il registro dei pacchetti API riporta 22 test backend e 17 UI/trasporto riusciti nel perimetro finale; la suite UI più ampia aveva due fallimenti su 90. OAuth reale delle mailbox, alcuni percorsi browser autenticati e QA mobile rimangono verifiche distinte. Per questo report sono stati riletti codice e registri: le suite applicative e i collaudi live non sono stati rieseguiti."),
        ],
        "callout": "Crescono anche gli strumenti di lavoro: controlli dei mock server nel monitor richieste, guide illustrate e materiali demo per Nencini Sport e GESCAT.",
        "sources": ["S01", "S02", "S11", "S13", "S17", "S18", "S37"],
    },
]


SOURCES = []


def source(id, title, path, revision=SNAPSHOT, repo="lopadova/AskMyDocs", local=None, note="Codice o documentazione versionata; lettura, senza riesecuzione del flusso."):
    SOURCES.append({"id": id, "title": title, "path": path, "revision": revision,
                    "repository": repo, "url": f"https://github.com/{repo}/blob/{revision}/{path}" if repo else None,
                    "local": local, "note": note, "checked_at": "2026-10-29"})


source("S01", "Intervallo mensile e HEAD remoto verificato", "composer.lock", note="Baseline cd1e917f7; snapshot develop 8003ca793, confrontato con API GitHub.")
source("S02", "Regia della ricerca per ondate e scadenze", "docs/handoff/agent-research-waves.md")
source("S03", "Risultati progressivi verificati", "app/Agent/ProgressiveAnswerPublisher.php")
source("S04", "Verifica della riscrittura dei fatti", "app/Agent/AgentAnswerPresenter.php")
source("S05", "Profili dei modelli per progetto", "docs/agent-project-model-profile.md")
source("S06", "Istruzioni persistenti del tenant", "app/Agent/AgentTenantInstructions.php")
source("S07", "Triage, campagne e provenienza delle email", "docs/email-triage.md")
source("S08", "Integrazione host dei pacchetti email v1", "docs/connectors/EMAIL-API.md")
source("S09", "Backfill e diagnostica IMAP", "docs/operations/imap-lock-diagnostics.md")
source("S10", "Gmail: funzioni e confini del pacchetto", "README.md", "ae1dbfd888ef4ce320e63cdaeb54cfeaddc65293", "padosoft/askmydocs-connector-gmail-api")
source("S11", "Gmail: verifica locale su due runtime", "docs/VERIFICATION.md", "ae1dbfd888ef4ce320e63cdaeb54cfeaddc65293", "padosoft/askmydocs-connector-gmail-api")
source("S12", "Microsoft Mail: funzioni e contratto", "README.md", "0131a5f085c701a4b12c6cb789ead1f441923441", "padosoft/askmydocs-connector-outlook-api")
source("S13", "Microsoft Mail: matrice di verifica locale", "docs/verification.md", "0131a5f085c701a4b12c6cb789ead1f441923441", "padosoft/askmydocs-connector-outlook-api")
source("S14", "Freshdesk: base integrata v1.0.0", "README.md", "c6066c02ec44d6bb6d35bb5af29537062e93d90d", "padosoft/askmydocs-connector-freshdesk")
source("S15", "Freshdesk: estensioni del checkout locale", "CHANGELOG.md", "e517cc2aac5227d32615b22ab1073bf767fc3515", None,
       "/Users/marco/packages/askmydocs-connector-freshdesk/CHANGELOG.md", "HEAD locale successivo alla revisione del lockfile; non prova una release consumer.")
source("S16", "Zendesk: consegna e verifiche locali", "docs/VERIFICATION.md", "3aa53403d3d7b37404aaefea3d2b8d2bf37bed5c", None,
       "/Users/marco/packages/askmydocs-connector-zendesk/docs/VERIFICATION.md", "Pacchetto locale, non nel lockfile; endpoint GitHub non accessibile.")
source("S17", "Pacchetti API: contratto e risultati di verifica", "docs/connectors/api-packages/verification-2026-10-21.md")
source("S18", "API tools: nucleo e verifica della consegna", "docs/handoff/composed-api-tools.md")
source("S19", "API tools: attivazione spenta per default", "config/api-tools.php")
source("S20", "OAuth/MCP: consenso, import e isolamento", "docs/security/mcp-project-oauth-boundaries.md")
source("S21", "PactAuth su develop e trasporto indisponibile", "docs/runbooks/pact-auth-sorgente.md")
source("S22", "Pacchetti plugin per connessioni MCP", "app/Services/Mcp/McpPluginPackage.php")
source("S23", "Identità del marchio e verifica responsive", "docs/brand/README.md")
source("S24", "Tredici temi: manifest e varianti giorno/notte", "public/editorial/themes/worlds-manifest.json")
source("S25", "Configurazione guidata e cataloghi live dei modelli", "docs/runbooks/runtime-configuration.md")
source("S26", "Versioni effettive del consumer", "composer.lock", note="Confronto con composer.lock alla baseline cd1e917f7; conta versione e SHA.")
source("S27", "MCP Pack: release 2.0.1 del 20 ottobre", "CHANGELOG.md", "d6b97a92001e0dbedcaab77f6636f4754f5a95d6", "padosoft/askmydocs-mcp-pack",
       note="Tag v2.0.1 verificato via GitHub. Consumer ancora v2.0.0.")
source("S28", "Evidence Risk Review: manutenzione docs/dist", ".gitattributes", "88130237e", "padosoft/laravel-evidence-risk-review",
       note="Otto commit sul ramo remoto predefinito; consumer ancora v1.4.0.")
source("S29", "Persistenza e recupero delle conversazioni", "app/Models/Conversation.php")
source("S30", "Ispezione dei riferimenti API in chat", "frontend/src/features/chat/ToolSourceModal.tsx")
source("S31", "Realtime: progressi confermati dal server", "frontend/src/features/chat/realtime-research-narrator.ts")
source("S32", "Configurazione conversazionale dei tool, ramo separato", "docs/handoff/composed-api-tools.md", "3cbe249f7", None,
       "/Users/marco/.codex/worktrees/dcb9/AskMyDocsDev/docs/handoff/composed-api-tools.md", "L'estensione conversazionale non è raggiungibile da develop.")
source("S33", "Bridge voce: aggiornamento ElevenLabs locale", "CHANGELOG.md", "5ab30fc03dc2fb8024babb400d4d379a705afa49", None,
       "/Users/marco/packages/agents-full-duplex-ui-bridge/CHANGELOG.md", "Modifica del checkout locale; revisione consumer distinta e invariata.")
source("S34", "Desktop: verifica UI e confini funzionali", "desktop/UI-VERIFICATION.md")
source("S35", "Apple: stato effettivo del 27 ottobre", "desktop/app-store/STATUS.md", note="Registro storico: nuovo TestFlight non verificato; nessuna nuova operazione Apple nel report.")
source("S36", "Performance: campioni e limiti delle misure", "docs/performance/2026-10-22-results.md")
source("S37", "Cloud: worker minimo e probe successive", "docs/handoff/agent-cloud-followup-queue.md")
source("S38", "Simulatore PACT: ramo e smoke documentato", "docs/runbooks/pact-agent-simulator.md", "853963542", None,
       "/Users/marco/.codex/worktrees/pact-agent-simulator/AskMyDocsPact/docs/runbooks/pact-agent-simulator.md", "Ramo separato; contratto knowledge, non il contratto oggetti Sorgente.")
source("S39", "Cerebras: valutazione senza inferenza", "docs/valutazione-cerebras-2026-10-26.md", "b6e7ccdbb", None,
       "/Users/marco/.codex/worktrees/8f54/AskMyDocsDev/docs/valutazione-cerebras-2026-10-26.md", "Studio locale; nessun benchmark di inferenza o adozione eseguiti.")


PACKAGE_CONNECTORS = [
    ("Gmail API", "Nuovo / v1.0.0", "Pacchetto e listener host integrati; consenso live da collaudare."),
    ("Microsoft Mail", "Nuovo / v1.0.0", "Pacchetto Graph e host integrati; consenso live da collaudare."),
    ("Freshdesk", "Nuovo / 1.0.0", "Base nel lockfile; estensioni successive presenti nel checkout locale."),
    ("Zendesk", "Nuovo / locale", "Due commit; assente dal lockfile. Repository remoto non accessibile."),
    ("API", "beta.1 > beta.5", "Upgrade nel consumer; due commit remoti di ottobre; identità locale separata."),
    ("IMAP", "v1.6.1 invariata", "Logo nel pacchetto locale; funzionalità operative sviluppate nell'host."),
    ("MCP connector", "v1.0.0 invariata", "Identità visiva locale; integrazioni MCP dell'host evolute separatamente."),
    ("MCP Pack", "v2.0.0 nel consumer", "Release remota v2.0.1: Guzzle 7/8 e floor Testbench; non adottata nel lock."),
    ("Confluence", "v1.2.0 invariata", "Un commit locale di identità visiva; nessun commit remoto sul default."),
    ("Evernote", "v1.2.0 invariata", "Un commit locale di identità visiva; nessun commit remoto sul default."),
    ("Fabric", "v1.2.0 invariata", "Un commit locale di identità visiva; nessun commit remoto sul default."),
    ("Google Drive", "v1.2.0 invariata", "Un commit locale di identità visiva; nessun commit remoto sul default."),
    ("Jira", "v1.2.0 invariata", "Un commit locale di identità visiva; nessun commit remoto sul default."),
    ("Notion", "v1.2.0 invariata", "Un commit locale di identità visiva; nessun commit remoto sul default."),
    ("OneDrive", "v1.2.0 invariata", "Un commit locale di identità visiva; nessun commit remoto sul default."),
    ("Connector base", "v1.6.0 invariata", "Contratto riusato dai nuovi pacchetti; nessun commit remoto di ottobre."),
    ("Connector template", "Locale invariato", "Nessun commit di ottobre. La cartella apib duplica la repository API."),
]


OUTSIDE = [
    block("PACT / A2A e simulatore", "Sul ramo feature/pact-agent-simulator vengono aggiunti ricezione di task knowledge vincolati a tenant/progetto, simulatore UI e trace di protocollo. Il registro include uno smoke reale autorizzato. I tre commit ad80228af, 88997d012 e 853963542 non sono raggiungibili dal develop analizzato. Il contratto knowledge non implementa il diverso contratto a oggetti richiesto da Sorgente."),
    block("Tool configurabili dalla conversazione", "Il ramo feature/composed-api-tools prosegue dopo il nucleo già integrato: anteprima di candidati non pubblicati, replay delle chiamate osservate, orchestrazione privata della configurazione e approvazione attraverso la chat. I commit 5a01b9cd6, e1b8d9ba3 e 3cbe249f7 restano esterni allo snapshot, con una consegna documentale dedicata."),
    block("Cerebras: ricerca di una direzione", "Il commit b6e7ccdbb, su codex/valutazione-cerebras-20261026, studia l'impiego selettivo nei passaggi testuali seriali. Propone un benchmark e identifica precedenze di modello, strumenti di misura e vincoli del JSON strutturato. Il documento dichiara che non sono state eseguite integrazione o inferenza: non rappresenta un miglioramento prestazionale già ottenuto."),
    block("Pacchetti e altre linee locali", "Freshdesk e la famiglia dei marchi connettori contengono lavoro locale successivo o distinto dalle revisioni consumate. Il bridge vocale locale riceve l'upgrade ElevenLabs v4 Turbo il 1° ottobre; il consumer mantiene la precedente revisione. Il registro allegato conserva anche gli altri commit fuori develop: copie ribasate, backup e varianti non vengono conteggiati come nuove funzionalità autonome."),
]
