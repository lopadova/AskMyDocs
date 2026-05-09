<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Steps\Prune\CountStaleChatLogsStep;
use App\Flow\Steps\Prune\DeleteStaleChatLogsStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.prune-chat-logs` — 2-step refactor of
 * {@see \App\Console\Commands\PruneChatLogsCommand}.
 *
 * Steps:
 *   1. count-stale-chat-logs     (dry-run-safe)
 *      Tenant-scoped count of chat_logs older than the cutoff.
 *   2. delete-stale-chat-logs    (mutates DB)
 *      Tenant-scoped DELETE. No compensator: chat_logs are operational
 *      telemetry and the user-facing chat surfaces (conversations +
 *      messages) are persisted in their own tables.
 *
 * Tenant fan-out: the CLI command iterates DISTINCT tenant_ids that
 * have chat_logs older than the cutoff and dispatches ONE Flow execute
 * call per tenant.
 */
final class PruneChatLogsFlow
{
    public const NAME = 'kb.prune-chat-logs';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                'tenant_id',
                'cutoff_iso',
            ])
            ->step('count-stale-chat-logs', CountStaleChatLogsStep::class)
                ->withDryRun(true)
            ->step('delete-stale-chat-logs', DeleteStaleChatLogsStep::class)
            ->register();
    }
}
