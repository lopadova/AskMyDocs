<?php

declare(strict_types=1);

namespace App\Agent\Evidence;

use App\Agent\Tools\AgentToolDefinition;
use App\Services\Widget\WidgetPiiMasker;
use JsonSerializable;

final class AgentEvidenceEnvelope implements JsonSerializable
{
    /** @var array<string,array<string,mixed>> */
    private array $documents = [];

    /** @var list<array<string,mixed>> */
    private array $apiTools = [];

    /** @var list<array{code:string,source:string|null}> */
    private array $warnings = [];

    public function __construct(private readonly WidgetPiiMasker $masker) {}

    /** @param array<string,mixed> $document */
    public function addDocument(array $document): void
    {
        $masked = $this->masker->maskArray($document) ?? [];
        $identity = (string) ($masked['document_id'] ?? $masked['source_path'] ?? 'unknown');
        $key = ($masked['origin'] ?? 'primary').':'.$identity;
        $evidence = is_array($masked['evidence'] ?? null) ? $masked['evidence'] : [];

        if (! isset($this->documents[$key])) {
            $masked['evidence'] = $this->uniqueEvidence($evidence);
            $masked['chunks_used'] = count($masked['evidence']);
            $this->documents[$key] = $masked;
            return;
        }

        $existing = $this->documents[$key];
        $existing['evidence'] = $this->uniqueEvidence(array_merge(
            is_array($existing['evidence'] ?? null) ? $existing['evidence'] : [],
            $evidence,
        ));
        $existing['headings'] = array_values(array_unique(array_merge(
            is_array($existing['headings'] ?? null) ? $existing['headings'] : [],
            is_array($masked['headings'] ?? null) ? $masked['headings'] : [],
        )));
        $existing['chunks_used'] = count($existing['evidence']);
        $this->documents[$key] = $existing;
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $result
     */
    public function addToolResult(
        AgentToolDefinition $tool,
        array $arguments,
        array $result,
        ?int $executionId = null,
    ): void {
        $maskedArguments = $this->masker->maskArray($arguments) ?? [];
        $maskedResult = $this->masker->maskArray($result) ?? [];
        $this->apiTools[] = [
            'execution_id' => $executionId,
            'tool' => $tool->name,
            'display_name' => $tool->displayName,
            'kind' => $tool->kind,
            'executor_reference' => $tool->executorReference,
            'presentation' => $this->presentation($tool),
            'arguments' => $maskedArguments,
            'result' => $maskedResult,
            'evidence_hash' => hash('sha256', (string) json_encode($maskedResult, JSON_UNESCAPED_UNICODE)),
            'retrieved_at' => now()->toIso8601String(),
        ];
    }

    public function addWarning(string $code, ?string $source = null): void
    {
        $this->warnings[] = ['code' => $code, 'source' => $source];
    }

    /** Import a previously masked checkpoint without changing its provenance. */
    public function import(array $payload): void
    {
        foreach (is_array($payload['documents'] ?? null) ? $payload['documents'] : [] as $document) {
            if (is_array($document)) {
                $this->addDocument($document);
            }
        }
        foreach (is_array($payload['api_tools'] ?? null) ? $payload['api_tools'] : [] as $tool) {
            if (is_array($tool)) {
                $this->apiTools[] = $this->masker->maskArray($tool) ?? [];
            }
        }
        foreach (is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [] as $warning) {
            if (is_array($warning) && is_string($warning['code'] ?? null)) {
                $this->addWarning($warning['code'], is_string($warning['source'] ?? null) ? $warning['source'] : null);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function documents(): array
    {
        return array_values($this->documents);
    }

    /** @return list<array<string,mixed>> */
    public function apiTools(): array
    {
        return $this->apiTools;
    }

    public function hasEvidence(): bool
    {
        return $this->documents !== [] || $this->apiTools !== [];
    }

    public function byteSize(): int
    {
        return strlen((string) json_encode($this->jsonSerialize(), JSON_UNESCAPED_UNICODE));
    }

    /** @return array{documents:list<array<string,mixed>>,api_tools:list<array<string,mixed>>,warnings:list<array{code:string,source:string|null}>} */
    public function jsonSerialize(): array
    {
        return [
            'documents' => $this->documents(),
            'api_tools' => $this->apiTools,
            'warnings' => $this->warnings,
        ];
    }

    /** @return array{collection_path:string|null,operation:string|null,identity_fields:list<string>,field_aliases:array<string,string>} */
    private function presentation(AgentToolDefinition $tool): array
    {
        $hint = is_array($tool->metadata['agent_capability_hint'] ?? null)
            ? $tool->metadata['agent_capability_hint']
            : [];
        $path = $hint['collection_path'] ?? $tool->metadata['items_path'] ?? null;
        if (! is_string($path) || preg_match('/^(?:\$|[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_*-]+)*)$/', $path) !== 1) {
            $path = null;
        }
        $operation = $hint['operation'] ?? $tool->metadata['endpoint_type'] ?? null;
        if (! is_string($operation) || ! in_array($operation, ['search', 'list', 'get', 'detail', 'summary', 'count', 'check'], true)) {
            $operation = null;
        }

        $builder = app(\App\Agent\Capabilities\AgentCapabilitySnapshotBuilder::class);
        $capability = $builder->build([$tool->name => $tool])->get($tool->name);
        $aliases = $duplicates = [];
        foreach ($builder->recordProperties($capability?->outputSchema, $path) as $field => $property) {
            if (! is_array($property)) {
                continue;
            }
            $roles = $property['x-field'] ?? [];
            foreach (is_string($roles) ? [$roles] : (is_array($roles) ? $roles : []) as $role) {
                if (! is_string($role) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $role)
                    || ! is_string($field) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $field)
                    || array_intersect((array) ($property['type'] ?? []), ['string', 'integer', 'number', 'boolean']) === []) {
                    continue;
                }
                if (isset($aliases[$role])) {
                    $duplicates[$role] = true;
                }
                $aliases[$role] = $field;
            }
        }
        $aliases = array_diff_key($aliases, $duplicates);

        return ['collection_path' => $path, 'operation' => $operation,
            'identity_fields' => $capability?->identityFields ?? [], 'field_aliases' => $aliases];
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function uniqueEvidence(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $hash = (string) ($item['evidence_hash'] ?? hash('sha256', (string) json_encode($item)));
            foreach ($unique as $existingHash => $existing) {
                if (! isset($item['chunk_id'], $existing['chunk_id']) || $item['chunk_id'] !== $existing['chunk_id']
                    || ! is_string($item['content'] ?? null) || $item['content'] === ''
                    || ! is_string($existing['content'] ?? null) || $existing['content'] === '') {
                    continue;
                }
                if (str_contains($existing['content'], $item['content'])) {
                    $unique[$existingHash]['research_flow_ids'] = array_values(array_unique([
                        ...($existing['research_flow_ids'] ?? []), ...($item['research_flow_ids'] ?? []),
                    ]));
                    continue 2;
                }
                if (str_contains($item['content'], $existing['content'])) {
                    $item['research_flow_ids'] = array_values(array_unique([
                        ...($existing['research_flow_ids'] ?? []), ...($item['research_flow_ids'] ?? []),
                    ]));
                    unset($unique[$existingHash]);
                }
            }
            if (isset($unique[$hash]['research_flow_ids']) || isset($item['research_flow_ids'])) {
                $item['research_flow_ids'] = array_values(array_unique([...($unique[$hash]['research_flow_ids'] ?? []), ...($item['research_flow_ids'] ?? [])]));
            }
            $unique[$hash] = $item;
        }

        return array_values($unique);
    }
}
