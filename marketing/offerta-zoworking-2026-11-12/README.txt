ASKMYDOCS - REVISIONE 05 ZOWORKING

Proposta aggiornata su istruzioni di Marco. Canone confermato 19 euro/mese per azienda, consumi esclusi; corso solo a ZoWorking in due giornate; quote 1.200 fino a dieci persone, 1.500 da undici a quindici; consulenza 700 euro al giorno, massimo tre giornate. Prezzi prima dei dettagli; ricariche in piccolo nelle ultime pagine.

Consegna cliente: AskMyDocs_ZoWorking.pdf, AskMyDocs_listino_ZoWorking.docx, Per_Lorenzo.txt, nello ZIP AskMyDocs_per_Lorenzo_ZoWorking.zip. Nota_costi_interna.txt e modello_costi_interno.json sono interni e non entrano nello ZIP.

Il corso prenota i posti del gruppo iscritto; la stessa classe può includere altre aziende. Accesso didattico e consumi degli esercizi compresi nelle due giornate. Ipotesi di durata organizzativa: sei ore di lezione per giornata. Per gruppi piccoli si valuta un preventivo dedicato.

Modelli di esempio recenti e tariffe verificati il 12 novembre 2026: GPT-6 Luna, GPT-6.1 Sol, Gemini 3.8 Flash, Gemini 4 Carbon, DeepSeek V4.1 Flash 1017. Le promozioni Gemini possono cambiare. Stime calcolate sui token di tutta la richiesta, ragionamento incluso; non sono misure medie di produzione. I contatori e i nuovi modelli vanno configurati e collaudati prima di applicare il listino.

Dalla radice della repository, usare il Python del runtime Codex:
python3 marketing/offerta-zoworking-2026-11-12/build.py
python3 marketing/offerte-con-formazione-2026-11-12/render_editorial.py --input marketing/offerta-zoworking-2026-11-12/brochure.json --project-root . --output marketing/offerta-zoworking-2026-11-12/AskMyDocs_ZoWorking.pdf --qa-dir marketing/offerta-zoworking-2026-11-12/qa/editoriale --overwrite

Il builder rigenera JSON, note, istruzioni per Lorenzo e Word; PDF, render, ZIP e consegna richiedono i passaggi separati. Renderizzare il Word con render_docx.py del runtime e il PDF con pdftoppm. Rivedere tutte le pagine interessate dopo modifiche e aggiornare ZIP e hash della consegna.

Tema, font, immagini e helper provengono dai materiali AskMyDocs già verificati: dipendenze esatte nella consegna. Questa proposta sostituisce la revisione 04 del solo AskMyDocs. Non cambia software, sito, billing o dati di produzione. Gli altri prodotti e il vecchio pacchetto congiunto non vengono aggiornati implicitamente. Nessuna pubblicazione o invio.
