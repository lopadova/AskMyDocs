<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Widget KITT — configurazione del canale embeddabile
|--------------------------------------------------------------------------
|
| Knob del widget chat RAG + agentico. `skills_path` è la radice dei manifest
| skill (tools_enabled + auto_annotation_rules + policy) letti da
| WidgetSkillRegistry. Sotto Orchestra Testbench `resource_path()` punta allo
| skeleton del pacchetto, quindi i test sovrascrivono questa chiave col path
| reale del progetto (vedi Tests\TestCase::getEnvironmentSetUp).
|
*/

return [
    'skills_path' => resource_path('widget/skills'),

    /*
    |--------------------------------------------------------------------------
    | M5.2 — Session Token TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | Durata di default dei token di sessione widget (wt_…). Il token è
    | consumato atomicamente (R21) e non riutilizzabile. Un valore più
    | basso aumenta la sicurezza ma richiede più mint dal FE.
    */
    'session_token_ttl_minutes' => (int) env('WIDGET_SESSION_TOKEN_TTL', 30),

    /*
    |--------------------------------------------------------------------------
    | M5.4 — Session rate-limit (requests per minute per session)
    |--------------------------------------------------------------------------
    |
    | Bucket separato dal per-key-per-IP. Limita il traffico su una singola
    | sessione widget. Se superato, il BE risponde 429 con header Retry-After.
    */
    'session_rate_limit_per_minute' => (int) env('WIDGET_SESSION_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | M5.5 — Message & step caps
    |--------------------------------------------------------------------------
    |
    | `max_message_length` — lunghezza massima del messaggio utente
    | (oltre questa → 422 esplicito, R14).
    | `max_steps_per_session` — numero massimo di step per sessione;
    | superato questo limite la sessione viene bloccata.
    */
    'max_message_length' => (int) env('WIDGET_MAX_MESSAGE_LENGTH', 10000),
    'max_steps_per_session' => (int) env('WIDGET_MAX_STEPS_PER_SESSION', 100),

    /*
    |--------------------------------------------------------------------------
    | #23 — Snapshot total-size cap (bytes)
    |--------------------------------------------------------------------------
    |
    | I cap di conteggio (fields/actions/…) non limitano la dimensione: uno
    | snapshot conforme nei conteggi ma con stringhe enormi fa esplodere il
    | prompt-token budget e la persistenza longText per turno. Cap totale sui
    | byte dello snapshot serializzato (oltre → 422). 0 disabilita il cap.
    */
    'snapshot_max_bytes' => (int) env('WIDGET_SNAPSHOT_MAX_BYTES', 262144),

    /*
    |--------------------------------------------------------------------------
    | #44 — Demo page (/widget-demo)
    |--------------------------------------------------------------------------
    |
    | La pagina demo pubblica conia/riusa una WidgetKey ATTIVA (pk_demo_local)
    | per il tenant default. Oltre al gate d'ambiente (local|testing) richiede
    | questo flag ESPLICITO (default OFF) così un box di staging lasciato a
    | APP_ENV=local non espone una credenziale funzionante a visitatori anonimi.
    */
    'demo_enabled' => (bool) env('WIDGET_DEMO_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Provider con function-calling OpenAI-shape (loop agentico)
    |--------------------------------------------------------------------------
    |
    | L'orchestratore passa `options.tools` all'LLM solo per i provider che
    | espongono il function-calling OpenAI-shape e popolano AiResponse::toolCalls.
    | openai/openrouter lo fanno; 'fake' emette tool_call scriptate per l'E2E
    | agentico (R13). Anthropic/Gemini/Regolo droppano `options.tools` nel loro
    | adapter attuale: abilitarli qui senza prima cablare l'emissione dei
    | toolCalls farebbe cadere il loop in SILENZIO su finishWithAnswer (R43).
    | Lista CSV così un operatore che cabla un nuovo provider può aggiungerlo
    | senza toccare il codice. Default: solo i provider realmente tool-capable.
    */
    'tool_calling_providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WIDGET_TOOL_CALLING_PROVIDERS', 'openai,openrouter,fake')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | M5.10 — Session retention (days)
    |--------------------------------------------------------------------------
    |
    | Widget sessions (and their cascade-deleted steps) older than this
    | number of days are hard-deleted by `widget:prune-sessions`.
    | Set to 0 to disable rotation entirely. The scheduler runs this
    | daily; see `config/askmydocs.php` › schedule.widget_prune_sessions.
    */
    'session_retention_days' => (int) env('WIDGET_SESSION_RETENTION_DAYS', 90),
];
