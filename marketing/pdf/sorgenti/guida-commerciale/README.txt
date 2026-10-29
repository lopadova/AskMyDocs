ASKMYDOCS / QUADERNO PER I COMMERCIALI

Destinatari: commerciali che propongono AskMyDocs a imprese del tessile,
della moda e della pelletteria dell'area fiorentina e dei raccordi di filiera.
Output locale: marketing/pdf/askmydocs-guida-commerciale.pdf, 32 pagine A4.
Tema: cartographic, distinto dal PowerPoint umanistico del prodotto.

I contenuti sono in contenuti_commerciali.py. I casi sono ipotetici e compositi;
le fonti territoriali sono primarie, datate e qualificate nella ricerca sotto
marketing/analisi/vendita-filiera-fiorentina-2026-10-29/.
Il riferimento funzionale resta lo snapshot 8003ca793c0218ec13451b449fa02a874de762a0.
Non vengono promessi listini, sconti, ROI, installazioni o integrazioni non verificati.

La produzione usa ReportLab, Georgia e Arial disponibili nel sistema macOS.
Usare il Python restituito da load_workspace_dependencies, per esempio:
/Users/marco/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3

Per una nuova operazione avviare il marker prescritto dalla skill PDF.
Poi, dalla root della repository:
  python3 marketing/pdf/sorgenti/guida-commerciale/build_guida.py
  pdftoppm -scale-to 1400 -png marketing/pdf/askmydocs-guida-commerciale.pdf marketing/pdf/sorgenti/guida-commerciale/.build/render/page
  python3 marketing/pdf/sorgenti/guida-commerciale/validate_guida.py

Il builder controlla le dimensioni e le collisioni dei rettangoli di testo.
Il validatore verifica il testo completo, le 30 domande dei casi, i 13 link,
il formato, i folii, i caratteri sostitutivi e le ancore esatte dei dati demo.
I contatti visivi e gli hash sono in .build/. Prima di consegnare una revisione
guardare tutte le pagine; il controllo automatico non sostituisce quello visivo.

Asset riutilizzati: public/editorial/themes/cartographic/.
Due nuove illustrazioni: marketing/immagini/guida-commerciale/.
Prompt, direzione e registro sono versionati; PNG, PDF, ZIP e render sono output
locali esclusi dai commit. Se le PNG mancano, rigenerarle con i prompt salvati.

Kit di nove file sintetici, con cinque verifiche attese e non osservate:
marketing/testi/vendita/guida-filiera-fiorentina/kit-demo/.
Il validatore ne produce anche l'archivio locale:
marketing/pdf/askmydocs-kit-demo-commerciale.zip.
I file non sono stati importati e la fixture JSON non prova un collegamento API.

Gli output e la QA specifica sono descritti in consegna.json. Il manifest
condiviso del marketing non viene promosso oltre il codice dirty preesistente;
questa consegna resta riferita alla versione prodotto dichiarata sopra.
