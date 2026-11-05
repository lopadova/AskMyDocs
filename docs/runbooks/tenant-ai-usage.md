# Consumi AI del tenant

La pagina **Consumi AI** si apre da **Amministrazione → Panoramica** e usa
la route `/app/{teamHash}/admin/ai-usage`. È riservata ai ruoli `admin` e
`super-admin` con accesso al tenant attivo. La ricerca del menu trova anche
«consumi». La pagina è integrata nella shell; non richiede l’attivazione della
SPA separata `AI_FINOPS_ADMIN_ENABLED`.

`GET /api/admin/ai-usage` aggrega il registro FinOps esistente della connessione
e del prefisso configurati. Non modifica dati, prezzi, budget o contabilizzazione.
Non restituisce prompt, risposte, metadata, credenziali o identificativi utente.
Il tenant deriva dal contesto autorizzato, mai da un parametro della query.

Il periodo comprende i giorni `from` e `to` nel `timezone` del report, con
confine finale esclusivo convertito in UTC. Il massimo è 366 giorni, raggruppati
in non più di 60 bucket. Provider, categoria, stato e valuta filtrano insieme
KPI, classifiche, grafici e dettaglio modelli. Ricerca e paginazione dei modelli
non alterano la panoramica. Le valute non vengono sommate o convertite.

Per default si leggono gli stati `recorded`, `covered` e `failed`. Le stime
preventive e le chiamate bloccate si possono ispezionare separatamente. Il volume
principale conta **voci del registro**, senza chiamarle domande o richieste utente.
I token totali sommano input e output; cache e reasoning sono sottoinsiemi.
Il costo per milione considera solo costi e token delle stesse voci tokenizzate,
escludendo costi OCR o altri metri; non viene mostrato con prezzi non verificabili.

Un costo nullo, oppure zero con token consumati e senza tariffa verificabile
nei metodi computed/estimated, è segnalato come non verificabile. Le righe
covered/actual a zero mantengono invece il significato dichiarato dal registro.
Per le voci tokenizzate senza costo viene calcolata una **stima da listino
attuale**, separata da `cost` in `estimated_cost`. La spesa visualizzata (`spend`)
somma costi registrati e stime, mentre `repriced_entries` ne dichiara la copertura.
KPI, classifiche e grafici sono etichettati come stime quando le includono e
mostrano fonte e data del listino. Non è una ricostruzione dell'importo fatturato:
il registro storico rimane invariato. Cache e reasoning non sono contati due
volte; i prezzi di valute diverse non vengono applicati. Prezzi assenti, modelli
non riconosciuti e tariffe automatiche negative restano non disponibili.
Se nessun costo è valorizzabile, classifiche e grafico usano i token registrati.

La pagina legge solo i listini già in cache, senza richieste esterne. Mantiene
la precedenza FinOps: override manuale, fonte del provider, poi listino più
recente e ordine configurato. OpenRouter comprende sia il catalogo chat sia
[quello degli embedding](https://openrouter.ai/docs/api_reference/embeddings).
Un refresh fallito conserva il catalogo completo precedente con la sua data.

Per le nuove chiamate sono attivi per default il listino OpenRouter e la raccolta
di [`usage.cost`](https://openrouter.ai/docs/cookbook/administration/usage-accounting).
Il costo dichiarato dal provider precede quello calcolato da tariffa. Le
variabili esplicitamente impostate nell'ambiente continuano a prevalere:

```dotenv
AI_FINOPS_PRICING_OPENROUTER=true
AI_FINOPS_ACTUAL_COST=true
```

Dopo il deploy, ricostruire l'eventuale cache della configurazione e inizializzare
i listini con `php artisan ai-finops:capture-prices`. Lo stesso comando ora
aggiorna i cataloghi prima degli snapshot nello slot notturno esistente
`finops_capture_prices`. Non esegue chiamate AI a pagamento né modifica consumi.

Il servizio aggrega in SQL; oltre 6.000 gruppi richiede un periodo più breve
o un filtro, senza tagliare silenziosamente i dati. Le serie mostrano fino a sei
modelli, oppure i primi cinque più «Altri», conservando i totali e le rettifiche
negative. Ogni grafico offre anche una tabella accessibile.

Verifiche mirate:

```sh
php vendor/bin/phpunit tests/Feature/FinOps tests/Feature/Security/AdminAuthorizationMatrixTest.php
node node_modules/vitest/vitest.mjs run --config vitest.config.ts frontend/src/features/admin/ai-usage frontend/src/components/shell/navigation.test.ts
npm run typecheck
npm run build
```
