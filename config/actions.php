<?php

return [
    'tools' => [
        // Search is a read effect. It can execute only after the skill
        // allowlist and tenant-bound retrieval path have accepted the call.
        'search_knowledge_base' => [
            'effect' => 'read',
            'executable' => true,
        ],
    ],
];
