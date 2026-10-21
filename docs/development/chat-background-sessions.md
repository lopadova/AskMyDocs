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

`ChatActivitySync` recupera lo stato dal server anche quando l'app si apre su
una pagina diversa dalla chat. Riprende gli stream esistenti dopo refresh o
riapertura, senza inviare di nuovo la domanda. Riconcilia i dati al ritorno in
primo piano e dopo la riconnessione; controlla ogni 5 secondi durante le
ricerche e ogni 30 secondi negli altri casi. Il recupero della cronologia è
limitato alla chat aperta, mentre la lista riceve solo metadati.

`GET /conversations/activity` è in sola lettura, include anche le chat archiviate
ed esclude le ricerche interne figlie. `POST /conversations/{id}/read` riceve
l'ID esatto della risposta e avanza soltanto il watermark
`conversations.last_read_message_id`. Non modifica `updated_at`; conferme
vecchie o ricevute da un'altra istanza dell'app non possono arretrare il valore
né leggere una risposta successiva. Entrambi gli endpoint rispettano utente
e tenant. Una conferma fallita viene ritentata alla riconciliazione successiva.

La migrazione `2026_10_21_150000_add_read_watermark_to_conversations_table.php`
inizializza come letta la cronologia preesistente per evitare notifiche
retroattive. È stata applicata su PostgreSQL locale Herd. In distribuzione
eseguire `php artisan migrate --force` e ricompilare gli asset; i worker
continuano a usare le esecuzioni durevoli già esistenti.

## Verifica del 21 ottobre 2026

- Suite chat, SSE e client HTTP: 522 test su 53 file. Backend: 43 test e
  206 asserzioni su attività, ownership, tenant, organizzazione, trasporto
  degli eventi e avvio dei turni. Coperti refresh, chiusura/riapertura,
  completamento ad app chiusa, conferme, ricevute fuori ordine e assenza di N+1.
- Verifica in sola lettura del servizio su PostgreSQL locale Herd: recuperate
  63 conversazioni senza caricare i contenuti delle risposte nel payload.
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
Le schermate della fixture sono conservate negli allegati del task. Il recupero
persistente è verificato dalle suite PHP e React; la sessione del browser
richiedeva nuovamente l'accesso, quindi non è stato effettuato un collaudo
manuale autenticato di questa estensione.
