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

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function uniqueEvidence(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $hash = (string) ($item['evidence_hash'] ?? hash('sha256', (string) json_encode($item)));
            $unique[$hash] = $item;
        }

        return array_values($unique);
    }
}
