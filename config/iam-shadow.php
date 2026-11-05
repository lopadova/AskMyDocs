<?php

return [
    /*
    |--------------------------------------------------------------------------
    | IAM resource/action manifest
    |--------------------------------------------------------------------------
    |
    | This is an inventory and shadow-PDP contract. It does not mount or
    | replace route middleware; an area is promoted only after its diff report
    | is reviewed and the equivalent policy is proven.
    */
    'resources' => [
        'knowledge_document' => [
            'required' => true,
            'actions' => [
                'view' => ['ability' => 'view'],
                'edit' => ['ability' => 'edit'],
                'delete' => ['ability' => 'delete'],
                'promote' => ['ability' => 'promote'],
            ],
        ],
        'kb_chat' => [
            'required' => true,
            'actions' => [
                'execute' => ['permission' => 'kb.chat'],
            ],
        ],
    ],
];
