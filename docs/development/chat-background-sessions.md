# Ricerche chat in background

`AgentChatSession` possiede stato e sottoscrizione SSE di una conversazione.
Il registro distingue utente, tenant e ID della chat. `useAgentChat` collega
soltanto la vista: il cleanup rimuove il lettore senza interrompere la ricerca.
L'annullamento esplicito resta associato alla singola sessione, anche prima
della risposta al POST di avvio. Le conferme e il cursore degli eventi vengono
conservati durante la navigazione.

Il completamento usa i callback catturati all'avvio del turno, così cronologia
e titolo vengono aggiornati nella conversazione di origine. Le richieste
mantengono il tenant originario anche dopo un cambio di team; un errore di
accesso del tenant in background non cambia il team attualmente aperto.
Logout e cambio utente eliminano il registro locale.

`ChatActivityBadge` è condiviso dalle liste Chat e Sessioni. Mostra ricerca,
conferma richiesta, errore oppure minuti interi dalla risposta non letta.
Il tempo parte da `created_at` dell'evento terminale, con fallback all'orologio
locale. Aprire la chat azzera il badge; risposte parziali seguono la stessa
regola. Annullamenti ed errori non vengono presentati come risposte da leggere.
Una cronologia in ritardo non cancella una nuova ricerca o una risposta già
caricata. Se si esce prima che la cronologia consegni la risposta alla vista,
questa rimane da leggere.

Il compositore protegge dai doppi invii per conversazione: una richiesta in
background non blocca un'altra chat e un suo errore non sovrascrive la bozza
della sessione aperta.

Il registro dura per la vita dell'app nel browser. Il ricaricamento completo
azzera gli indicatori locali; esecuzione dell'agente e messaggi rimangono
persistiti sul server. Non sono richieste migrazioni o modifiche ai worker.

## Verifica del 21 ottobre 2026

- Suite chat, SSE e client HTTP verificata su 51 file; 45 test mirati
  superati sulla versione finale, inclusi cronologia in ritardo e retry
  della continuazione.
- TypeScript e build app/widget verificati con `npm run typecheck` e
  `npm run build`. Rimane l'avviso Vite sulla dimensione dei bundle.
- Browser su Herd con fixture temporanea dei componenti reali, senza chiamate
  AI o scritture di conversazioni sul database: avvio, nuova chat, due ricerche
  simultanee, risposta in background, badge e lettura alla riapertura.
- Temi chiaro/scuro; viewport 320×568, 390×844, 430×932, 667×375 e 768×1024:
  nessun overflow orizzontale, righe alte 48 px e badge visibili con titoli
  lunghi. `prefers-reduced-motion` disattiva l'animazione dell'indicatore.

Mobile UI review: PASS per le righe e i badge nel browser responsive.
Landing sync: invariata; colori, font e superfici usano i token correnti.
Gli stati di errore, annullamento, conferma e retry sono coperti dai test.
La verifica non comprende dispositivi nativi, tastiera virtuale,
VoiceOver/TalkBack o ingrandimento del testo al 200%.
Le schermate della fixture sono conservate negli allegati del task.
