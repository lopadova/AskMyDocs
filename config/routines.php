<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Tick
    |---------------------------------------------------------------------------
    | `schedule` registra `routines:tick` nello scheduler di Laravel al posto tuo.
    | Mettilo a false se preferisci gestirlo tu (supervisor, cron di sistema, k8s).
    */
    'tick' => [
        'schedule' => true,
        'limit' => 100,
    ],

    /*
    |---------------------------------------------------------------------------
    | Lock
    |---------------------------------------------------------------------------
    | Per quanto un worker tiene una routine prima di essere considerato morto.
    | Deve essere piu' lungo del fire piu' lento che ti aspetti: troppo corto e due
    | worker girano insieme; troppo lungo e una routine bloccata resta ferma a lungo.
    */
    'lock_seconds' => 900,

    /*
    |---------------------------------------------------------------------------
    | Recupero delle occorrenze perse
    |---------------------------------------------------------------------------
    | Il tetto e' una scelta di sicurezza, non di performance: una routine ogni 5
    | minuti ferma per un giorno ha 288 occorrenze arretrate, e recuperarle tutte
    | significa mandare 288 email in trenta secondi.
    */
    'catch_up_cap' => 25,

    /*
    |---------------------------------------------------------------------------
    | Tentativi
    |---------------------------------------------------------------------------
    | Backoff esponenziale a partire da questa base: 1', 2', 4', 8'...
    */
    'retry_base_seconds' => 60,

    /*
    |---------------------------------------------------------------------------
    | Admin API
    |---------------------------------------------------------------------------
    | L'API che alimenta il pannello (padosoft/laravel-routines-admin) e qualsiasi
    | altro client. Vive QUI e non nel pacchetto del pannello, deliberatamente: se
    | domani il pannello viene sostituito l'API resta, e con lei tutto cio' che ci
    | si integra sopra.
    |
    | Il middleware e' tuo: `auth` usa il guard web. L'autorizzazione fine passa dal
    | Gate (routines.read / .write / .fire / .approve) ed e' fail-closed - senza
    | policy definite si ottiene sola lettura.
    |
    | v8.39/W5 (ADR 0033 §5) — spento di default in QUESTA app: questo Gate
    | generico non conosce il confine di tenant di AskMyDocs (`organization_id`
    | e' un campo opaco per il pacchetto), quindi senza policy dedicate
    | qualsiasi utente autenticato di QUALSIASI tenant leggerebbe le routine di
    | tutti. Il tri-surface tenant-scoped e RBAC-gated di AskMyDocs
    | (`kb:wiki-routine`, `/api/admin/kb/wiki-routine`, `KbWikiRoutineStatusTool`)
    | e' l'unica via sanzionata per leggere o lanciare una routine in questa
    | applicazione.
    */
    'api' => [
        'enabled' => env('ROUTINES_API_ENABLED', false),
        'prefix' => env('ROUTINES_API_PREFIX', 'api/routines/v1'),
        'middleware' => ['web', 'auth'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Ingresso webhook
    |---------------------------------------------------------------------------
    | Rotta separata e SENZA sessione: la chiama una macchina, che non ha cookie
    | ne' CSRF e non deve averne. Al posto della sessione c'e' una firma
    | HMAC-SHA256 sul corpo grezzo, con un segreto per routine.
    |
    | Il throttle non e' decorativo: e' l'unica difesa contro chi prova firme a
    | ripetizione, dato che la rotta e' per definizione esposta.
    */
    'webhooks' => [
        'enabled' => env('ROUTINES_WEBHOOKS_ENABLED', true),
        'prefix' => env('ROUTINES_WEBHOOKS_PREFIX', 'hooks/routines'),
        'middleware' => ['throttle:60,1'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Bersagli
    |---------------------------------------------------------------------------
    */
    'targets' => [

        'job' => [
            'enabled' => true,

            /*
            | I job che una routine puo' mettere in coda.
            |
            | Vuoto per default, ed e' deliberato: il payload di una routine e' dato
            | (form, import, in futuro una proposta di un assistente), e istanziare
            | la classe che ci trovi dentro significherebbe dare a quel dato il
            | diritto di eseguire qualsiasi cosa esista nell'applicazione. Elenca qui
            | cosa e' lecito schedulare.
            */
            'allowed' => [
                // App\Jobs\SendDailyDigest::class,
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Risolutori opzionali
    |---------------------------------------------------------------------------
    | Il core non conosce il modello utente della tua applicazione ne' il dominio
    | dei bersagli, quindi non indovina: senza questi risolutori il pannello mostra
    | l'identificativo canonico e il riferimento grezzo, che sono comunque veri.
    | Con essi mostra un'email e un link.
    |
    | 'owner_label_resolver'  => fn (string $owner): ?string => ...,
    | 'external_url_resolver' => fn (string $targetType, string $ref): ?string => ...,
    */
    'owner_label_resolver' => null,
    'external_url_resolver' => null,

    /*
    |---------------------------------------------------------------------------
    | Tetti predefiniti
    |---------------------------------------------------------------------------
    | Valori proposti dalla UI quando si crea una routine. Non sono un limite
    | globale: il limite vero e' quello scritto sulla singola routine.
    */
    'defaults' => [
        'timezone' => null,      // null = config('app.timezone')
        'max_attempts' => 3,
        'timeout_seconds' => 300,
        'currency' => 'EUR',
    ],
];
