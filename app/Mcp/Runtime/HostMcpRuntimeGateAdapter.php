<?php

declare(strict_types=1);

namespace App\Mcp\Runtime;

use Padosoft\AskMyDocsConnectorMcp\Contracts\McpRuntimeGateContract;

final readonly class HostMcpRuntimeGateAdapter implements McpRuntimeGateContract
{
    public function __construct(private McpRuntimeGate $runtime) {}

    public function active(?string $tenantId = null): bool
    {
        return $this->runtime->usesConnector($tenantId);
    }
}
