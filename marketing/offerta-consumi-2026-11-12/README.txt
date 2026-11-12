ASKMYDOCS REVISIONE 04 A CONSUMO - SORGENTI E CONSEGNA

Questa revisione sostituisce la proposta AskMyDocs della revisione 03 da 149/299/599 euro mensili. Non modifica gli altri prodotti o i pacchetti congiunti precedenti. Fonte dei prezzi nuovi: offerta.json. Le condizioni di durata del credito e rinnovo accesso sono nuove proposte, non prezzi o funzioni già attivati.

Consegna a Lorenzo: AskMyDocs_consumi.pdf, AskMyDocs_listino_consumi.docx e Per_Lorenzo.txt nello ZIP AskMyDocs_per_Lorenzo_consumi.zip. Nota_stime_interna.txt e modello_stime_interno.json sono interni e non entrano nello ZIP.

Le stime sono aritmetiche: tariffe pubbliche verificate il 12 novembre 2026 e token ipotizzati per l'intera operazione. Nessuna inferenza a pagamento o benchmark di produzione è stato eseguito. Prezzi reali osservati, ipotesi commerciali, cambio prudenziale e costi di personale sono distinti nei sorgenti.

Dalla radice della repository usare il Python del runtime Codex:
python3 marketing/offerta-consumi-2026-11-12/build.py
python3 marketing/offerte-con-formazione-2026-11-12/render_editorial.py --input marketing/offerta-consumi-2026-11-12/brochure.json --project-root . --output marketing/offerta-consumi-2026-11-12/AskMyDocs_consumi.pdf --qa-dir marketing/offerta-consumi-2026-11-12/qa/editoriale --overwrite

Il builder rigenera JSON, nota interna, istruzioni per Lorenzo e Word. Per il Word usare render_docx.py della skill documents del runtime Codex; per il PDF usare pdftoppm. Dopo aggiornamenti significativi verificare tutte le pagine interessate e aggiornare ZIP e consegna con gli hash finali. Lo ZIP e consegna.json non sono rigenerati dal builder.

Template: griglia, logo, font e immagini editoriali già verificati di AskMyDocs; dipendenze esatte con hash in consegna.json. Output binari e render restano locali; solo sorgenti, nota e provenienza sono versionati. Nessuna modifica al software, sito o dati di produzione, nessun invio o pubblicazione.
