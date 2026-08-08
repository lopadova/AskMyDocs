<?php

declare(strict_types=1);

return [
    'unknown' => 'L’assistente sta lavorando.',
    'run' => [
        'started' => 'Avvio la ricerca.',
        'completed' => 'La risposta è pronta.',
        'partial' => 'Le informazioni disponibili sono pronte, ma la ricerca non è completa.',
        'failed' => 'Non sono riuscito a completare la ricerca.',
        'cancelled' => 'La ricerca è stata annullata.',
        'awaiting_confirmation' => 'Servono altre chiamate per continuare questa ricerca.',
    ],
    'retrieval' => [
        'started' => 'Cerco nella nuvola dei documenti.',
        'completed' => 'Ricerca documentale completata: :count fonti pertinenti.',
    ],
    'plan' => [
        'created' => 'Pianifico le fonti dati necessarie.',
        'updated' => 'Aggiorno il piano di ricerca con i dati trovati.',
    ],
    'tool' => [
        'queued' => ':tool è in coda.',
        'started' => 'Sto chiamando :tool.',
        'progress' => 'Completate :completed richieste API su circa :estimated.',
        'completed' => ':tool completato.',
        'failed' => 'Non è stato possibile completare :tool.',
    ],
    'budget' => [
        'extended' => 'La ricerca è stata estesa in sicurezza per raccogliere i dati rimanenti.',
        'limit_reached' => 'È stato raggiunto il limite corrente della ricerca.',
    ],
    'synthesis' => [
        'started' => 'Unisco i documenti e i dati aggiornati.',
        'streaming' => 'Sto preparando la risposta.',
    ],
    'error' => [
        'unauthorized' => 'Questa fonte dati non è disponibile per il progetto corrente.',
        'rate_limited' => 'Il servizio esterno ha temporaneamente limitato le richieste.',
        'timeout' => 'Il servizio esterno ha impiegato troppo tempo a rispondere.',
        'unavailable' => 'Il servizio esterno non è temporaneamente disponibile.',
        'invalid_response' => 'Il servizio esterno ha restituito una risposta non supportata.',
        'budget_exhausted' => 'La ricerca si è fermata al limite di sicurezza configurato.',
    ],
];
