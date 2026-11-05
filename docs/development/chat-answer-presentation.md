# Presentazione delle risposte chat

## Contratto

`Markdown` mantiene separati contenuto e presentazione. La variante `answer`,
usata da `MessageBubble` e dalla chat anonima, applica una gerarchia editoriale
a titoli, paragrafi, liste, tabelle, citazioni e codici già presenti nel Markdown.
Non inventa titoli, non riscrive risposte salvate e non modifica il validatore.
La stessa resa vale per messaggi persistiti e streaming, inclusa la trascrizione
delle risposte vocali visualizzata dal componente condiviso.

Le anteprime KB mantengono la densità precedente. Toolbar del codice, callout e
contenitori delle tabelle sono condivisi; i colori seguono i token del tema.
Le tabelle larghe scorrono nel proprio riquadro, accessibile da tastiera.
I controlli di copia e apertura delle fonti usano il `Button` canonico; sui
telefoni hanno un target alto almeno 48 px. Nessuna dipendenza aggiunta.

Le regole editoriali del sintetizzatore già esistenti restano invariate:
sezioni per argomento, titoli brevi, paragrafi, enfasi selettiva e liste quando
utili. Il renderer ora rende riconoscibile questa struttura anche dopo il
reset CSS di Tailwind.

## Verifica — 30 settembre 2026

Mobile UI review: PASS per i controlli responsive web sotto elencati.

- Schermata e flusso: lettura di una risposta esistente su Herd e fixture
  temporanea dei componenti reali, senza nuove chiamate AI o scritture nella chat.
- Piattaforme e viewport: browser Chromium; chat a 1440×1000, 390×844 e 320×568;
  fixture anche a 430×932, 667×375 e 768×1024.
- Landing sync: invariata. Ripreso il ritmo editoriale; conservati font Geist
  e palette dell'app, senza trasferire il layout della landing nella chat.
- Usabilità e stati: testo semplice, sezioni multiple, elenchi, tabelle,
  callout, codice, fonti. Streaming e copia riuscita/fallita coperti da test.
- Accessibilità e motion: testo al 200% (32 px) senza overflow della risposta;
  temi chiaro/scuro; RTL; regioni scorrevoli raggiungibili con tastiera e focus
  visibile; frecce per scorrere la tabella; animazione della risposta disattivata
  con `prefers-reduced-motion`. Icone SVG decorative escluse dall'albero accessibile.
- Adattività: a 320 px la risposta reale misura 172 px senza overflow; nella
  fixture la tabella mantiene 480 px interni, ma scorre in un contenitore di
  224 px. Il codice lungo scorre indipendentemente. Nessun overlay nuovo.
- Correzioni applicate: gerarchia dei titoli, spaziatura, marker delle liste,
  citazioni originali distinguibili, header/righe delle tabelle, toolbar codice,
  fonti separate dal testo, controlli condivisi e target touch.
- Limiti: verifica nativa iOS/Android, tastiera virtuale, safe area fisiche e
  VoiceOver/TalkBack non eseguita (BLOCKED per queste verifiche). Il controllo
  nel browser non costituisce certificazione di accessibilità o test su device.
  Nessuna modifica alla navigazione o al composer mobile preesistenti.

Comandi ripetibili:

```sh
bun run test frontend/src/lib/markdown frontend/src/features/chat/MessageBubble.test.tsx frontend/src/features/chat/CitationsPopover.test.tsx frontend/src/features/chat/AnonymousChatView.test.tsx frontend/src/features/chat/AgentTableArtifact.test.tsx frontend/src/features/chat/MessageActions.test.tsx
bun run build
```

Non usare il setup E2E con reset del database per questa verifica visiva.
La build comprende TypeScript, app e widget; resta il warning informativo
sulla dimensione dei bundle, non un errore di compilazione.

## Rilascio

Compilare gli asset e ricaricare il browser. Nessuna migrazione o modifica
dell'ambiente; nessun riavvio dei worker. Anche le vecchie risposte beneficiano
del nuovo stile, purché contengano la corrispondente struttura Markdown.
