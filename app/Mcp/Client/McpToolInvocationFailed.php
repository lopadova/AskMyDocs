<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use RuntimeException;

/**
 * A failed MCP tool call that still cost wire messages. It is a plain
 * `RuntimeException` for every existing caller; the agent budget reads
 * `$physicalRequests` so a failure is charged what it really sent.
 */
final class McpToolInvocationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $physicalRequests)
    {
        parent::__construct($message);
    }
}
