ASKMYDOCS TEAM - SORGENTI E CONSEGNA

Ambito: revisione 03 del solo AskMyDocs. Utenti illimitati a team; software, credito AI, consulenza e corso separati. Nessun aggiornamento del sito o della fatturazione di produzione. Nessuna modifica agli altri tre prodotti.

File per Lorenzo: AskMyDocs_team.pdf, AskMyDocs_listino_team.docx e Per_Lorenzo.txt, raccolti nello ZIP AskMyDocs_per_Lorenzo.zip.
Nota_costi_interna.txt e modello_costi_interno.json sono interni e non sono nello ZIP.

build.py rigenera brochure.json, offerta.json, modello_costi_interno.json, fonti.json, Nota_costi_interna.txt e il Word. Non rigenera da solo il PDF, i render di QA, lo ZIP o consegna.json.

Dalla radice della repository, usare il Python del runtime Codex:
python3 marketing/offerta-team-2026-11-12/build.py
python3 marketing/offerte-con-formazione-2026-11-12/render_editorial.py --input marketing/offerta-team-2026-11-12/brochure.json --project-root . --output marketing/offerta-team-2026-11-12/AskMyDocs_team.pdf --qa-dir marketing/offerta-team-2026-11-12/qa/editoriale --overwrite

Per il Word usare render_docx.py della skill documents del runtime Codex. Per il PDF usare pdftoppm. Dopo ogni modifica significativa verificare tutte le pagine interessate, ricalcolare lo ZIP e aggiornare gli hash in consegna.json.

Dipendenze: helper Word e renderer PDF del precedente pacchetto, tema e font della brochure approvata, immagini editoriali e logo del prodotto. Gli hash esatti sono nella consegna.

Limite commerciale: il listino è una proposta. Prima dell'applicazione configurare e collaudare misura completa dei consumi, saldo condiviso, prenotazione del budget e code per team. I costi Cloud osservati e le ipotesi di personale sono distinti nella nota interna; i margini sono contribuzione simulata, non utile netto.
