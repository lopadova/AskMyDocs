ASKMYDOCS / QUADERNO PER I COMMERCIALI

Destinatari: commerciali che propongono AskMyDocs a imprese del tessile,
della moda e della pelletteria dell'area fiorentina e dei raccordi di filiera.
Output locale: marketing/pdf/askmydocs-guida-commerciale.pdf, 32 pagine A4.
Tema: atelier di moda pittorico, con bozzetti di abiti, borse e cappelli;
nessuna mappa geografica nelle immagini. Palette navy/pergamena/terracotta,
font Georgia/Arial e impaginazione restano quelli della guida approvata.

I contenuti sono in contenuti_commerciali.py. I casi sono ipotetici e compositi;
le fonti territoriali sono primarie, datate e qualificate nella ricerca sotto
marketing/analisi/vendita-filiera-fiorentina-2026-10-29/.
Il riferimento funzionale resta lo snapshot 8003ca793c0218ec13451b449fa02a874de762a0.
Non vengono promessi listini, sconti, ROI, installazioni o integrazioni non verificati.

La produzione usa ReportLab, Georgia e Arial disponibili nel sistema macOS.
Le illustrazioni sono incorporate in JPEG qualità 92 senza ridimensionamento;
ritagli, coordinate e dimensioni di impaginazione restano invariati.
Usare il Python restituito da load_workspace_dependencies, per esempio:
/Users/marco/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3

Per una nuova operazione avviare il marker prescritto dalla skill PDF.
Poi, dalla root della repository:
  python3 marketing/pdf/sorgenti/guida-commerciale/build_guida.py
  pdftoppm -scale-to 1400 -png marketing/pdf/askmydocs-guida-commerciale.pdf marketing/pdf/sorgenti/guida-commerciale/.build/render/page
  python3 marketing/pdf/sorgenti/guida-commerciale/validate_guida.py --skip-kit-archive

Il builder controlla le dimensioni e le collisioni dei rettangoli di testo.
Il validatore verifica il testo completo, le 30 domande dei casi, i 13 link,
il formato, i folii, i caratteri sostitutivi e le ancore esatte dei dati demo.
I contatti visivi e gli hash sono in .build/. Prima di consegnare una revisione
guardare tutte le pagine; il controllo automatico non sostituisce quello visivo.

Sei asset pittorici: marketing/immagini/guida-commerciale/.
Copertina e caso 1: atelier-tessile.png; casi 2 e 6: archivio-bozzetti.png;
caso 3: atelier-pelletteria.png; caso 4: tavolo-stilista.png;
caso 5: atelier-confronto-giorno.png; chiusura: atelier-confronto-sera.png.
Le immagini sostituiscono tutte le mappe con bozzetti di moda e accessori.
Le fonti e le citazioni territoriali del testo commerciale restano invariate.
Prompt, direzione e registro sono versionati; PNG, PDF, ZIP e render sono output
locali esclusi dai commit. Se le PNG mancano, rigenerarle con i prompt salvati.

Kit di nove file sintetici, con cinque verifiche attese e non osservate:
marketing/testi/vendita/guida-filiera-fiorentina/kit-demo/.
Senza --skip-kit-archive il validatore ne produce anche l'archivio locale:
marketing/pdf/askmydocs-kit-demo-commerciale.zip.
I file non sono stati importati e la fixture JSON non prova un collegamento API.

Gli output e la QA specifica sono descritti in consegna.json. Il manifest
condiviso del marketing non viene promosso oltre il codice dirty preesistente;
questa consegna resta riferita alla versione prodotto dichiarata sopra.
