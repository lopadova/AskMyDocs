<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use JsonSerializable;

final readonly class AgentToolDefinition implements JsonSerializable
{
    /**
     * @param array<string,mixed> $inputSchema
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $name,
        public string $displayName,
        public string $description,
        public string $kind,
        public array $inputSchema,
        public bool $readOnly,
        public bool $idempotent,
        public int $physicalMinimum,
        public int $physicalLikely,
        public int $physicalMaximum,
        public string|int|null $executorReference = null,
        public array $metadata = [],
    ) {
        if ($name === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid agent tool name [{$name}].");
        }
        if (! in_array($kind, ['knowledge', 'api', 'mcp', 'client'], true)) {
            throw new \InvalidArgumentException("Invalid agent tool kind [{$kind}].");
        }
        if ($physicalMinimum < 0 || $physicalMinimum > $physicalLikely || $physicalLikely > $physicalMaximum) {
            throw new \InvalidArgumentException('Tool physical estimates must satisfy 0 <= min <= likely <= max.');
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'kind' => $this->kind,
            'input_schema' => $this->inputSchema,
            'capabilities' => [
                'read_only' => $this->readOnly,
                'idempotent' => $this->idempotent,
                'physical_estimate' => [
                    'min' => $this->physicalMinimum,
                    'likely' => $this->physicalLikely,
                    'max' => $this->physicalMaximum,
                ],
            ],
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Planner-safe representation. Remote metadata is intentionally reduced to
     * host-enforced execution facts; arbitrary MCP _meta never reaches the LLM.
     *
     * @return array<string,mixed>
     */
    public function plannerPayload(): array
    {
        $payload = $this->jsonSerialize();
        $metadata = $this->metadata;
        unset($metadata['meta'], $metadata['provenance'], $metadata['agent_capability_hint']);
        $payload['metadata'] = $metadata;
        $payload['trust'] = ['description' => 'untrusted_remote_data'];

        return $payload;
    }

    /** @return array<string,mixed> */
    public function openAiFunction(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->inputSchema,
            ],
        ];
    }
}
