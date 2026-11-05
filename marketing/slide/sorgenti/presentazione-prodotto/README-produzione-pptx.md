# Produzione PowerPoint AskMyDocs

Il deck locale usa i testi di `contenuti-slide.json` e il brief approvato. Titoli, corpo, schemi e richiami sono elementi PowerPoint nativi; le illustrazioni sono incorporate come immagini. Ogni slide conserva note per il relatore e riferimenti al PDF di partenza.

Per rigenerare usare il Node del runtime Codex e una revisione nuova, per esempio:

```sh
/Users/marco/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node build_deck.mjs --revision=r4
```

Il comando va eseguito solo dopo il marker di avvio richiesto dalle skill Presentations/PDF per una nuova operazione. Il marker non va ripetuto nei passaggi interni della stessa operazione.

`build_deck.mjs` usa `@oai/artifact-tool`, controlla il layout e i font Georgia/Arial, esporta, convalida e reimporta il file prima di produrre tutte le PNG. Il file finale è `marketing/slide/presentazione.pptx`.

`build_audit.py` verifica nel pacchetto PPTX la presenza di ogni campo testuale principale come testo nativo e la completezza delle venti note. `build_preview.py` produce l’anteprima PDF dai rendering, la rigenera con Poppler e confronta tutte le venti pagine. Il PDF è una prova visiva raster. Il PowerPoint resta il file modificabile.

La cartella `.build` contiene candidati e rapporti di convalida. `.validated` conserva copie immutate delle revisioni che hanno superato la finalizzazione. `marketing/slide/consegna-pptx.json` indica la revisione consegnata e i limiti verificati. Dopo una rigenerazione il suo stato rimane in attesa della revisione visiva di tutte le slide.

Le verifiche sono state eseguite con Artifact Tool e Poppler. Non è stata effettuata un’apertura in Microsoft PowerPoint. Georgia e Arial devono essere disponibili sul computer usato per presentare.
