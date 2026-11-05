ASKMYDOCS / IL SAPERE CHE ENTRA NEL LAVORO

Documento di presentazione del prodotto, in italiano, 32 pagine PDF e versione
HTML autonoma. Mantiene la grafica del report editoriale di ottobre approvato
dall'utente: stessi font, palette, griglia, copertina, filetti, riquadri e foliazione.

STRUTTURA
- 1: copertina.
- 2-6: definizione, valore, percorso della risposta, pubblici e controllo.
- 7-11: cinque scenari dimostrativi, non testimonianze cliente.
- 12: proposta di introduzione e prova circoscritta.
- 13: apertura della guida funzionale.
- 14-29: guida alle sezioni del prodotto.
- 30: disponibilità e condizioni di configurazione.
- 31: note e riferimenti dell'edizione.
- 32: invito a partire da un compito concreto.

PERIMETRO
Snapshot prodotto: 8003ca793c0218ec13451b449fa02a874de762a0, 29 ottobre 2026.
Le 58 capacità implementate e le 47 voci menu sono mappate nella presentazione.
Due sviluppi non operativi sul consumer, Zendesk e trasporto PACT, sono esclusi
dalla presentazione delle capacità disponibili. Active nel catalogo significa
implementato, non necessariamente attivato nell'ambiente.

FONTI E MATERIALI
- contenuti_prodotto.py: testo della presentazione e mappa delle sezioni.
- build_presentazione.py: impaginazione PDF/HTML; riusa il motore grafico del
  report ottobre senza modificarlo.
- marketing/analisi/presentazione-prodotto-2026-10-29/: inventario, mappa completa
  delle sezioni, fonti e nota di verifica.
- marketing/testi/vendita/presentazione-prodotto-2026-10-29/: casi d'uso con
  prerequisiti, sequenze ed evidenze; posizionamento e demo.
- marketing/immagini/linea-editoriale-askmydocs.json: linea grafica riutilizzabile.
- marketing/immagini/presentazione-prodotto/registro.json: due nuove illustrazioni,
  prompt integrali, riferimenti di stile, origine e hash.

OUTPUT GENERATI LOCALI
marketing/pdf/askmydocs-presentazione-prodotto.pdf
marketing/pdf/askmydocs-presentazione-prodotto.html
marketing/fonti/presentazione-prodotto/mappa-contenuti.json
marketing/fonti/presentazione-prodotto/layout-verifica.json
marketing/fonti/presentazione-prodotto/consegna.json

RIGENERAZIONE
Usare il Python del runtime Codex, con ReportLab/Pillow e i font locali macOS,
dalla root del repository:

python3 marketing/pdf/sorgenti/presentazione-prodotto/build_presentazione.py

I due PNG generati con image_gen devono restare nella cartella immagini indicata.
La libreria grafica condivisa viene importata dai sorgenti del report ottobre;
conservare anche marketing/fonti/ottobre-2026/audit.json, letto al suo import.
I PDF, l'HTML, le immagini generate e i file di verifica restano esclusi dal
commit. I sorgenti di testo, design e analisi sono versionati.

VERIFICHE
Tutte le 32 pagine sono state renderizzate e ispezionate. Il controllo di layout
non trova sovrapposizioni fra i paragrafi. La versione HTML è stata controllata
in Chromium a 1440 e 390 pixel: immagini, ancore, ricerca nel catalogo delle 58
capacità, mappa delle 47 sezioni, nessun overflow o errore JavaScript.
Le affermazioni rinviano al codice e ai documenti nel commit indicato; non sono
stati rieseguiti test applicativi o attivati mailbox, account, API o demo live.

STATO CONDIVISO
L'helper marketing ha inizializzato lo stato della cartella. La base condivisa
non è stata avanzata perché permane una modifica preesistente al solo test
tests/Feature/Agent/AgentChatModelScopeTest.php. Il documento fa riferimento
esplicito al codice committato 8003ca793, non al working tree, e conserva una
consegna propria. La modifica al test non è stata toccata o inclusa nel commit.

Nessun push, PR, invio, pubblicazione o modifica del prodotto fa parte del lavoro.
