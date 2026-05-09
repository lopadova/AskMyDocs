<?php

declare(strict_types=1);

$queueLockSeconds = env('LARAVEL_FLOW_QUEUE_LOCK_SECONDS', 3600);
$queueLockRetrySeconds = env('LARAVEL_FLOW_QUEUE_LOCK_RETRY_SECONDS', 30);
$queueTries = env('LARAVEL_FLOW_QUEUE_TRIES', null);
$queueBackoffSeconds = env('LARAVEL_FLOW_QUEUE_BACKOFF_SECONDS', null);
$approvalTokenTtlMinutes = env('LARAVEL_FLOW_APPROVAL_TOKEN_TTL_MINUTES', 1440);
$webhookTimeoutSeconds = env('LARAVEL_FLOW_WEBHOOK_TIMEOUT_SECONDS', 5);
$webhookRetryBaseDelaySeconds = env('LARAVEL_FLOW_WEBHOOK_RETRY_BASE_DELAY_SECONDS', 30);
$webhookMaxAttempts = env('LARAVEL_FLOW_WEBHOOK_MAX_ATTEMPTS', 3);

return [

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    |
    | Persistence remains opt-in: the in-memory engine path still works with no
    | database writes. When enabled, synchronous engine runs are written to the
    | configured connection and common secret-looking payload keys are redacted
    | before JSON payloads are stored.
    |
    */
    'default_storage' => env('LARAVEL_FLOW_STORAGE', null),

    'persistence' => [
        'enabled' => env('LARAVEL_FLOW_PERSISTENCE_ENABLED', false),

        'redaction' => [
            'enabled' => env('LARAVEL_FLOW_REDACTION_ENABLED', true),
            'replacement' => env('LARAVEL_FLOW_REDACTION_REPLACEMENT', '[redacted]'),
            'keys' => [
                'api_key',
                'authorization',
                'password',
                'secret',
                'token',
            ],
        ],

        'retention' => [
            'days' => env('LARAVEL_FLOW_RETENTION_DAYS', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue execution
    |--------------------------------------------------------------------------
    |
    | Flow::dispatch() queues a RunFlowJob. Each queued job uses a per-dispatch
    | cache lock before executing so duplicate delivery cannot run the same
    | queued flow concurrently. Duplicate deliveries that find the lock held
    | are released for another attempt; duplicates that arrive after a run has
    | completed are acknowledged as no-ops. Set the lock TTL longer than the
    | expected maximum flow runtime; Laravel's portable lock contract cannot
    | renew it. The store must support shared Laravel atomic locks; the
    | process-local array store is accepted only when the queue driver is sync.
    | Optional tries/backoff values are normalized at dispatch time and captured
    | into the job payload so worker retry behavior is explicit and visible to
    | Laravel queue tooling. Because async workers retry the whole wrapper job,
    | retry policies that can re-run a flow are rejected until step-level retry
    | or replay semantics are available.
    |
    */
    'queue' => [
        'lock_store' => env('LARAVEL_FLOW_QUEUE_LOCK_STORE', null),
        'lock_seconds' => is_numeric($queueLockSeconds) && (int) $queueLockSeconds >= 1
            ? (int) $queueLockSeconds
            : 3600,
        'lock_retry_seconds' => is_numeric($queueLockRetrySeconds) && (int) $queueLockRetrySeconds >= 1
            ? (int) $queueLockRetrySeconds
            : 30,
        'tries' => $queueTries,
        'backoff_seconds' => $queueBackoffSeconds,
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval gates
    |--------------------------------------------------------------------------
    |
    | Approval resume/reject tokens are generated as high-entropy one-time
    | secrets. Only their SHA-256 hashes are persisted in flow_approvals.
    |
    */
    'approval' => [
        'token_ttl_minutes' => is_numeric($approvalTokenTtlMinutes) && (int) $approvalTokenTtlMinutes >= 1
            ? (int) $approvalTokenTtlMinutes
            : 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | When true, normal-case transitions dispatch the matching Laravel event.
    | If persistence is enabled, events are emitted only after required audit
    | appends succeed. Persisted `flow_audit` rows require persistence to be
    | enabled, this flag to be true, and the execution to be non-dry-run.
    |
    */
    'audit_trail_enabled' => env('LARAVEL_FLOW_AUDIT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Dry-run default
    |--------------------------------------------------------------------------
    |
    | When true, Flow::execute() with no explicit dry-run flag still simulates
    | (i.e. caller must opt INTO real persistence). Guard rail for staging
    | environments.
    |
    */
    'dry_run_default' => env('LARAVEL_FLOW_DRY_RUN_DEFAULT', false),

    /*
    |--------------------------------------------------------------------------
    | Webhook delivery outbox
    |--------------------------------------------------------------------------
    |
    | Signed webhook delivery is optional. Enable it when you want Laravel Flow
    | lifecycle events to be delivered outside the package boundary.
    |
    */
    'webhook' => [
        'enabled' => env('LARAVEL_FLOW_WEBHOOK_ENABLED', false),
        'url' => env('LARAVEL_FLOW_WEBHOOK_URL', ''),
        'secret' => env('LARAVEL_FLOW_WEBHOOK_SECRET', null),
        'retry_base_delay_seconds' => is_numeric($webhookRetryBaseDelaySeconds) && (int) $webhookRetryBaseDelaySeconds > 0
            ? (int) $webhookRetryBaseDelaySeconds
            : 30,
        'max_attempts' => is_numeric($webhookMaxAttempts) && (int) $webhookMaxAttempts > 0
            ? (int) $webhookMaxAttempts
            : 3,
        'timeout_seconds' => is_numeric($webhookTimeoutSeconds) && (int) $webhookTimeoutSeconds > 0
            ? (int) $webhookTimeoutSeconds
            : 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Step timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Reserved for v0.2 — when a step runs in a queued worker the wrapper job
    | will use this as its timeout. Currently informational only.
    |
    */
    'step_timeout_seconds' => (int) env('LARAVEL_FLOW_STEP_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Compensation strategy metadata
    |--------------------------------------------------------------------------
    |
    | Supported values:
    | - reverse-order: default saga rollback, newest completed step first.
    | - parallel: batch independent compensators through Laravel Concurrency.
    |
    | Only use parallel when compensators are independent, idempotent, and safe
    | to run without reverse-order dependencies.
    |
    */
    'compensation_strategy' => env('LARAVEL_FLOW_COMPENSATION', 'reverse-order'),

    /*
    |--------------------------------------------------------------------------
    | Parallel compensation driver
    |--------------------------------------------------------------------------
    |
    | Used only when compensation_strategy is "parallel". Laravel's process
    | driver gives actual process-level parallelism; set "sync" in local tests
    | or when compensators must stay in the current PHP process.
    |
    */
    'compensation_parallel_driver' => env('LARAVEL_FLOW_COMPENSATION_PARALLEL_DRIVER', 'process'),

];
