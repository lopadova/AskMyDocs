<?php

return [
    'authorization_ttl_seconds' => 600,
    'code_ttl_seconds' => 300,
    'token_ttl_days' => 30,
    'scopes' => [
        'kb:read' => 'Search and read the documents you can access',
        'mcp:read' => 'Search and read documents in the one project you select',
        'mcp:ingest' => 'Submit documents to that project, with browser approval before indexing',
        'kb:chat' => 'Ask questions using your knowledge base',
    ],
];
