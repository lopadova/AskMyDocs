<?php

return [
    // Project activation is also required. This switch stops new paid work.
    'enabled' => env('KB_KNOWLEDGE_ENABLED', true),
    'connection' => env('KB_KNOWLEDGE_CONNECTION', 'knowledge'),
    'queue' => env('KB_KNOWLEDGE_QUEUE', 'knowledge'),
    'extractor_version' => 'sections-v1',
    'section_chars' => 8000,
    'sections_per_job' => 8,
    'provider' => env('KB_KNOWLEDGE_PROVIDER'),
    'model' => env('KB_KNOWLEDGE_MODEL'),
];
