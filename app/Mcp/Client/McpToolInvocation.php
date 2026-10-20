<?php

declare(strict_types=1);

namespace App\Mcp\Client;

/**
 * The outcome of one MCP tool call together with the number of physical
 * JSON-RPC messages the pack actually sent for it (negotiation, protocol
 * revision fallbacks, session recovery and the `tools/call` itself). The agent
 * budget charges this count rather than assuming one message per call.
 */
final readonly class McpToolInvocation
{
    /** @param array<string,mixed> $result */
    public function __construct(
        public array $result,
        public int $physicalRequests,
    ) {}
}
