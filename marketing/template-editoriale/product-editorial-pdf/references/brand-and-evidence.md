# Marchio, immagini e prove

## Un'identità ricavata, non applicata sopra

Leggi e osserva la pagina d'ingresso effettiva. Registra route/URL, file della pagina, stylesheet e asset, data e revisione. Rileva:

1. Logo e wordmark, varianti chiare/scure e relativo contesto d'uso. Conservali integri e proporzionati. Se serve una conversione SVG-PNG/PDF, usa la risorsa originale senza ridisegnarla.
2. Famiglie e pesi realmente caricati, non soltanto elencati in un fallback CSS. Usa file font del progetto o risorse disponibili e utilizzabili; per WOFF/WOFF2 verifica la conversione di formato con gli strumenti disponibili. Se il font non è accessibile scegli il più vicino disponibile e documenta la sostituzione.
3. Colori di carta/sfondo, testo, accenti, superfici e filetti. La gerarchia viene dal riferimento; i valori vengono dal sito. Verifica contrasto e lettura in stampa.
4. Grammatica delle immagini: tecnica, palette, luce, materiali, persone/ambienti e densità. Un tema origami richiede immagini origami; un editoriale fotografico richiede coerenza con quel trattamento. Non rendere tutti i prodotti umanistici se la loro landing non lo è.

Il file `brand.json` deve permettere a un'altra sessione di capire da quale fonte viene ogni scelta. Non raccogliere credenziali o interi file ambiente per cercare un dominio.

## Nuove immagini

Riusa prima gli asset editoriali del prodotto che hanno un compito nella storia. Per una nuova scena usa `imagegen`: dai come riferimento asset già ispezionati, descrivi il compito e salva prompt e output. Il nome del prodotto e il logo si impaginano con elementi reali, senza chiedere al generatore di ricrearli.

Varia in modo pertinente le scene: copertina, persone al lavoro, caso applicativo, collegamento o passaggio, chiusura. Non riempire il PDF con la stessa figura ripetuta. L'illustrazione spiega il contesto; la prova di una funzionalità resta nel codice/documentazione e negli screenshot reali.

Per ogni asset conserva file, hash, dimensioni, origine, uso/pagine, prompt se sintetico e limiti d'uso conosciuti. Segna le immagini come illustrative dove serve per non confonderle con clienti, risultati o interfacce reali. Non inventare una licenza per un asset trovato online.

## Fatti e copertura

Un inventario essenziale contiene `id`, nome, stato di implementazione, stadio di rilascio, disponibilità osservata/configurabile, beneficio osservabile, prove (file e revisione), prerequisiti e pagine del documento. Se lo stato tenant viene dal database e non è stato letto, registralo come non osservato: la migration iniziale non prova il valore corrente. Le macroaree dell'app e le capacità aggiuntive devono avere una collocazione leggibile. Le funzionalità sperimentali, pianificate o non attive non diventano universali nella sintesi.

Per i casi conserva domanda, input, sequenza possibile, risultato qualitativo, limite e fonti. Un caso ipotetico è etichettato come tale e non ha metriche o citazioni cliente inventate. Se la demo non è stata eseguita, descrivi un percorso possibile, senza dire che il risultato è stato osservato.

Se la landing promette più del codice o usa un nome diverso dal progetto, registra lo scarto. Usa le capacità verificate nel PDF e mantieni il marchio reale richiesto dall'utente. La realizzazione del documento non autorizza a correggere il sito, implementare funzioni o cambiare il brand.

## Accettazione pratica

La mappa delle pagine collega ogni claim alla prova e ogni asset all'origine. Il PDF mantiene testo selezionabile e font incorporati quando supportato. Verifica tutte le pagine a dimensioni leggibili, comprese tabelle, note e fonti; un contact sheet serve per il ritmo, non per attestare da solo il dettaglio. Il report di consegna riporta esattamente i controlli svolti e il loro perimetro.
