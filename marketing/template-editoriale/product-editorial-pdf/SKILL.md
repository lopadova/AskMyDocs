---
name: product-editorial-pdf
description: Crea presentazioni di prodotto in PDF con la struttura editoriale approvata di AskMyDocs, adattando font, colori, loghi e immagini alla landing del prodotto. Usa per brochure di presentazione complete e brandizzate; non per report mensili o manuali commerciali salvo richiesta esplicita.
---

# Presentazione editoriale di prodotto

Realizza un PDF locale finito, illustrato e verificato. Conserva la struttura narrativa e l'impaginazione del riferimento approvato; ricava l'identità visiva dal prodotto destinatario. La skill è un modello operativo PDF, non un template della Template Gallery.

## Riferimento e risultato

Leggi [la struttura editoriale](references/editorial-blueprint.md) e [il contratto visivo](references/brand-and-evidence.md). Il riferimento completo è [la presentazione approvata](assets/reference-product.pdf); le pagine campione sono in `assets/reference-pages/`. AskMyDocs serve a mostrare griglia, ritmo e profondità: i suoi testi, loghi, palette e illustrazioni non diventano quelli di un altro prodotto.

Default italiano, A4 verticale, circa 32 pagine quando i contenuti lo sostengono. Apri con identità, valore e utilizzi; presenta i casi prima del catalogo delle funzionalità. Mantieni lo stesso ordine fra progetti. Adatta il numero di pagine alla sostanza verificata, senza ripetizioni per riempire una quota. Non produrre PowerPoint, guida per commerciali, campagne o rifacimenti del sito se non richiesti.

Salva tutto in `<repo>/marketing/`, normalmente:

- `pdf/<slug>-presentazione-prodotto.pdf`;
- `pdf/sorgenti/presentazione-prodotto/contenuti.json`, builder e istruzioni di rigenerazione;
- `immagini/presentazione-prodotto/` per asset, prompt, registro e direzione;
- `analisi/presentazione-prodotto/` per inventario, fonti, copertura e revisione;
- `fonti/presentazione-prodotto/` per render, QA e consegna con hash.

Se esistono materiali omonimi, leggi provenienza e modifiche umane prima di aggiornare; prepara una revisione candidata in caso di conflitto.

## 1. Identificare prodotto e versione

Leggi AGENTS, README, cartella marketing, codice, documentazione e landing. Distingui prodotti omonimi, versione web/desktop e vecchie denominazioni. Registra revisione Git, dirty state e percorsi letti. Non cambiare il software per rendere vere le promesse del documento.

Ricostruisci le funzioni dimostrate, i pubblici e i flussi reali. Per ogni affermazione conserva feature ID, prove e condizioni, separando stato di implementazione, stadio di rilascio (per esempio alpha/beta/production) e disponibilità osservata o configurabile nell'installazione. Una funzione implementata può essere ancora alpha o non abilitata per quel tenant. Non presentare roadmap o testo storico come capacità attuale. I benefici sono spiegazioni del meccanismo; numeri di risparmio e testimonianze richiedono prove proprie.

Per un prodotto desktop consulta l'app desktop e la landing pertinente; non attribuirle automaticamente l'intero backend, una versione mobile o la versione web. Un working tree modificato si può leggere come stato osservato, distinguendolo dal commit; non bloccare la brochure per una modifica estranea e non attribuire quella modifica a una release.

## 2. Derivare il marchio dalla landing

Ispeziona la landing reale e i suoi sorgenti: logo/wordmark, CSS, font effettivi e pesi, token colore, immagini, tema editoriale, luci, materiali e composizione. Usa il dominio e lo schema Herd già configurati per l'anteprima locale. Per siti citati consulta il sito o i suoi contenuti accessibili; non dedurre il branding dal solo nome della repository. Se non puoi aprire la landing, usa sorgenti e asset ispezionati, distinguendo questo riscontro da una visita visiva dal vivo e registrando il limite.

Salva un `brand.json` con prove puntuali, file dei font, palette, logo e asset. Il PDF usa quei font e quei colori, con i soli adattamenti di contrasto e stampa necessari. Un wordmark composto da testo e SVG nella landing si può riprodurre fedelmente da quel componente e dai font originali: è il marchio esistente, non un logo da inventare. Se il marchio pubblicato ha un nome diverso dal progetto, documenta la relazione e usa il logo reale; non disegnare un nuovo marchio per risolvere la differenza.

Riusa le immagini editoriali coerenti del sito. Se mancano scene utili, usa la skill `imagegen` e il tool di generazione disponibile, con riferimenti visivi del progetto e prompt salvati. Genera tavole pertinenti alle attività descritte, senza testo o loghi inventati. Le immagini restano illustrative; gli screenshot devono provenire dall'app reale con dati dimostrativi. Non riusare scene AskMyDocs soltanto per comodità.

## 3. Scrivere e comporre

Segui il blueprint: copertina; essenziali; valore e meccanismo; persone; casi; prima prova; divisore; funzionalità sezione per sezione; condizioni; fonti; chiusura. Titoli brevi e specifici, sommari esplicativi, due colonne con blocchi editoriali, richiami utili. I dettagli tecnici servono soltanto quando cambiano la comprensione o una decisione del lettore.

Per ogni caso descrivi situazione, domanda, percorso, risultato qualitativo e prerequisiti. Etichetta gli esempi ipotetici/compositi; non trasformare aziende-fonte in clienti. Copri le capacità principali con una mappa feature-pagina e metti le condizioni vicino alla promessa interessata.

Per produrre il file leggi la skill PDF corrente dal catalogo e usa `load_workspace_dependencies`. Rispetta il suo marker di avvio e la consegna. Puoi usare [il renderer parametrico](scripts/render_editorial.py) e [l'esempio di input](assets/example-content.json), copiandoli nei sorgenti del progetto. Leggi `--help` e [le note del renderer](references/renderer.md) per il formato. Non importa moduli AskMyDocs e non richiede audit o configurazioni di altri progetti. Un altro motore va bene se riproduce la struttura e supera gli stessi controlli.

## 4. Verificare e consegnare

Apri il PDF, estrai il testo, verifica link, numero di pagine e copertura. Renderizza **tutte** le pagine e guardale: griglia, folii, contrasto, font, assenza di clipping e sovrapposizioni, ritagli che preservano volti e mani, loghi proporzionati, tabelle leggibili. Correggi i problemi e rivedi le pagine cambiate. Non dichiarare una verifica visiva sulla base del solo testo estratto o del contact sheet.

Conserva nel report finale hash del PDF e degli input, revisione prodotto, brand derivato, asset e prompt, mappa delle pagine, verifiche realmente fatte, limiti ed eventuali lacune. La fonte deve restare modificabile. Il PDF e le immagini possono rimanere output locali secondo le regole Git del progetto; non includere segreti o modifiche preesistenti nei commit.

La consegna è il PDF concreto e apribile, con un collegamento locale e la citazione prevista dalla skill PDF. Nessuna pubblicazione o invio è implicito. Quando il lavoro viene lanciato in più progetti, passa a ogni sessione questa skill e lo stesso blueprint, indicando repository e variante di prodotto; usa ownership separate e non far scrivere le sessioni nella cartella di un altro prodotto.
