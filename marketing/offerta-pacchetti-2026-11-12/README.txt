ASKMYDOCS - REVISIONE 06 PACCHETTI E AVVIO

Prezzi pubblici totali: S 29 euro/mese con 1.000 crediti; M 49 con 3.000; L 79 con 6.000. Non esporre la suddivisione economica interna software/credito esclusa da Marco. Utenti illimitati e stesse funzioni; tutti gli importi IVA esclusa.

Riga consigliata evidenziata in verde nel PDF e nel Word: 1.500 euro con corso completo di due giornate a ZoWorking fino a dieci persone e una giornata di consulenza aziendale inclusa. Abbonamento software separato. Corsi singoli: una giornata 600, due giornate 1.200. Consulenza 700 al giorno, massimo tre giornate totali inclusa quella del percorso.

I crediti mensili non si accumulano; ricariche extra senza scadenza, spendibili con abbonamento attivo. Upgrade nel periodo con differenza intera di prezzo e solo credito aggiuntivo, stessa data di rinnovo; downgrade dal successivo rinnovo. Regole commerciali da configurare e collaudare, non già attivate.

Consegna cliente: AskMyDocs_pacchetti_e_avvio.pdf, AskMyDocs_listino_pacchetti.docx e Per_Lorenzo.txt, nello ZIP AskMyDocs_per_Lorenzo_pacchetti.zip. Nota_costi_interna.txt e modello_costi_interno.json restano interni e sono esclusi dallo ZIP.

Il bundle 1.500 è modellato con classi condivise con più aziende. L'edizione dedicata a un solo gruppo ha contribuzione negativa nelle ipotesi conservative; due gruppi nella stessa classe rendono positiva la contribuzione per azienda, con consulenze comunque dedicate. La nota interna conserva entrambi gli scenari. Non è utile netto o un preventivo reale dell'aula.

Dalla radice della repository, con il Python del runtime Codex:
python3 marketing/offerta-pacchetti-2026-11-12/build.py
python3 marketing/offerta-pacchetti-2026-11-12/render_editorial.py --input marketing/offerta-pacchetti-2026-11-12/brochure.json --project-root . --output marketing/offerta-pacchetti-2026-11-12/AskMyDocs_pacchetti_e_avvio.pdf --qa-dir marketing/offerta-pacchetti-2026-11-12/qa/editoriale --overwrite

Il builder importa senza eseguirla la revisione 05 per calcoli dei modelli e helper. Il renderer locale estende quello approvato con highlight_rows senza modificare l'originale. Dopo cambiamenti significativi renderizzare Word con render_docx.py del runtime e PDF con pdftoppm, rivedere le pagine e aggiornare ZIP e consegna con gli hash finali. Il builder non rigenera PDF, QA, ZIP o consegna.json.

Sostituisce solo AskMyDocs revisione 05. Gli altri prodotti e i vecchi pacchetti congiunti non sono aggiornati. Tema, asset e font riutilizzati con provenienza. Nessun software, billing, sito o dato di produzione modificato. Nessuna pubblicazione o invio.
