<?php

declare(strict_types=1);

return [
    'unknown' => 'The assistant is working.',
    'run' => [
        'assessing' => 'I’m checking the information already available.',
        'started' => 'I am starting the search.',
        'completed' => 'The answer is ready.',
        'partial' => 'The available information is ready, but the search is incomplete.',
        'failed' => 'I could not complete the search.',
        'cancelled' => 'The search was cancelled.',
        'awaiting_confirmation' => 'More calls are needed to continue this search.',
    ],
    'research' => [
        'queued_after_partial' => 'The search for the missing details is queued. You can keep chatting.',
        'queued_without_answer' => 'I need more information to answer. The search is queued.',
        'started' => 'I’m looking for the missing information.',
    ],
    'retrieval' => [
        'started' => 'Searching the document knowledge base.',
        'completed' => 'Document search completed: :count relevant sources.',
    ],
    'plan' => [
        'created' => 'Planning the required data sources.',
        'updated' => 'Updating the search plan with the data found.',
    ],
    'tool' => [
        'queued' => 'Queued :tool.',
        'started' => 'Calling :tool.',
        'progress' => ':completed of about :estimated API requests completed.',
        'completed' => ':tool completed.',
        'failed' => ':tool could not be completed.',
    ],
    'budget' => [
        'extended' => 'The search was safely extended to collect the remaining data.',
        'limit_reached' => 'The current search limit has been reached.',
    ],
    'synthesis' => [
        'started' => 'Combining documents and live data.',
        'streaming' => 'Writing the answer.',
    ],
    'error' => [
        'unauthorized' => 'This data source is not available for the current project.',
        'rate_limited' => 'The external service is temporarily rate limited.',
        'timeout' => 'The external service took too long to respond.',
        'unavailable' => 'The external service is temporarily unavailable.',
        'invalid_response' => 'The external service returned an unsupported response.',
        'budget_exhausted' => 'The search stopped at the configured safety limit.',
    ],
];
