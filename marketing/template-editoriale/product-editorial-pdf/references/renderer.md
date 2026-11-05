# Renderer PDF parametrico

Il renderer è un aiuto per conservare la struttura. I testi e la direzione visiva restano lavoro editoriale del progetto. Non contiene chiamate di rete, credenziali o import dei vecchi report AskMyDocs.

## Avvio

Usa il Python restituito da `load_workspace_dependencies`, con ReportLab, Pillow e pypdf. Esegui prima il marker della skill PDF corrente, una sola volta per l'operazione. Copia script ed esempio nei sorgenti marketing, adatta il JSON e avvia:

```sh
python3 marketing/pdf/sorgenti/presentazione-prodotto/render_editorial.py \
  --input marketing/pdf/sorgenti/presentazione-prodotto/contenuti.json \
  --project-root /percorso/assoluto/del/progetto \
  --output /percorso/assoluto/del/progetto/marketing/pdf/prodotto-presentazione-prodotto.pdf \
  --qa-dir /percorso/assoluto/del/progetto/marketing/fonti/presentazione-prodotto
```

`--overwrite` sostituisce un output già presente quando è la revisione che stai rigenerando. Per un documento esistente modificato a mano conserva l'originale e usa un nome candidato.

## Dati e risorse

L'esempio in `assets/example-content.json` mostra tutti gli otto layout: `cover`, `article`, `journey`, `case`, `divider`, `table`, `references`, `closing`. Il docstring del renderer descrive i campi. Le pagine sono esplicite: il motore misura e fallisce sull'overflow, non inserisce silenziosamente pagine o riduce il testo fino a renderlo illeggibile.

Il JSON contiene `document`, `theme` e `pages`. In `theme.palette` sono obbligatori `paper`, `cream`, `ink`, `primary`, `accent`, `muted`, `line`, `pale`. Derivali dal sito e conserva la fonte in `palette_source` e nel registro di marca.

`theme.fonts.heading` e `.body` accettano file TTF `regular`, `bold`, `italic`, `bold_italic`. Puoi convertire font web disponibili e utilizzabili al formato supportato. Il fallback è esplicito e viene segnalato nella QA; l'esempio sintetico usa Times/Helvetica soltanto per essere eseguibile senza risorse proprietarie. Le presentazioni reali devono prima cercare i font della landing.

Logo e immagini usano `{ "path": "percorso/nel/progetto.png", "fit": "cover", "focus": [0.5, 0.5] }`. I percorsi sono relativi a `--project-root`; anche i symlink devono risolversi al suo interno. Se una risorsa comune si trova fuori dalla root scelta, copiala nel marketing del progetto con provenienza registrata, oppure scegli la vera root del monorepo mantenendo l'output nel marketing autorizzato. Il logo di norma usa `contain`. Converti SVG da risorse originali a un formato supportato senza ridisegnare il marchio.

`focus` è il punto della sorgente da mantenere vicino al centro del ritaglio, con coordinate 0-1 dall'alto a sinistra. Rivedi sempre il risultato visivo. I file originali restano immutati; per il PDF le immagini vengono proporzionate, ricampionate e compresse. I testi sono semplici, non HTML; nuove righe e liste di paragrafi sono supportate nei campi documentati.

Ogni pagina può conservare `source_ids` e `feature_ids`. Le fonti vere con URL sono voci `references.items`; i riferimenti locali senza URL vanno descritti con revisione e percorso. Non esporre URL inventati per rendere cliccabile una nota.

## QA e riparazione

Il motore salva `page-map.json`, `layout.json`, `render-report.json`, con geometrie, scelte font, immagini e provenienza. Controlla l'estrazione del testo e scrive il PDF finale soltanto dopo le verifiche strutturali. Conserva il report insieme ai sorgenti della revisione.

Renderizza con Poppler tutte le pagine, poi ispezionale individualmente. Se una pagina eccede lo spazio, riduci ripetizioni, distribuisci meglio i blocchi o aggiungi una pagina motivata; non ignorare l'errore. Dopo tre tentativi sullo stesso errore tecnico senza progresso, conserva il candidato e descrivi il limite preciso, continuando le parti indipendenti. Un fallimento del motore non vieta un altro renderer appropriato e verificato.

Il pass automatico non prova la correttezza dei claim, la qualità dell'immagine o il comportamento dell'app. Questi controlli appartengono alla revisione editoriale e visiva.
