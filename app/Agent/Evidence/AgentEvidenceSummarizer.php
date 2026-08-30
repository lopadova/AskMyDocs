<?php

declare(strict_types=1);

namespace App\Agent\Evidence;

use App\Agent\Capabilities\AgentCapabilitySnapshot;

final class AgentEvidenceSummarizer
{
    /** @return array<string,mixed> */
    public function summarize(AgentEvidenceEnvelope $evidence, ?AgentCapabilitySnapshot $snapshot = null): array
    {
        $documents = array_map(function (array $document): array {
            $chunks = is_array($document['evidence'] ?? null) ? $document['evidence'] : [];

            return array_filter([
                'document_id' => $document['document_id'] ?? null,
                'title' => $document['title'] ?? $document['source_path'] ?? null,
                'headings' => array_slice(is_array($document['headings'] ?? null) ? $document['headings'] : [], 0, 5),
                'evidence' => array_map(static fn (array $chunk): array => array_filter([
                    'heading' => $chunk['heading'] ?? null,
                    'score' => $chunk['score'] ?? null,
                    'excerpt' => mb_substr((string) ($chunk['content'] ?? ''), 0, 500),
                ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []), array_slice($chunks, 0, 3)),
            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        }, array_slice($evidence->documents(), 0, 8));

        $tools = array_map(function (array $item) use ($snapshot): array {
            $name = is_string($item['tool'] ?? null) ? $item['tool'] : '';
            $result = is_array($item['result'] ?? null) ? $item['result'] : [];
            $capability = $snapshot?->get($name);
            $collection = $this->collection($result, $capability?->collectionPath);
            $identityFields = $capability?->identityFields ?? [];
            if ($identityFields === [] && is_array($collection) && is_array($collection[0] ?? null)) {
                $identityFields = array_values(array_intersect(
                    ['id', 'public_id', 'uuid', 'code', 'number', 'slug'],
                    array_keys($collection[0]),
                ));
            }

            return array_filter([
                'tool' => $name,
                'kind' => $item['kind'] ?? null,
                'arguments' => $this->bounded($item['arguments'] ?? []),
                'top_level_keys' => array_slice(array_keys($result), 0, 20),
                'collection_path' => $capability?->collectionPath,
                'row_count' => is_array($collection) ? count($collection) : null,
                'identity_fields' => $identityFields,
                'identifier_samples' => $this->identifierSamples($collection, $identityFields),
                'record_preview' => $this->recordPreview($result, $collection),
                'sample_keys' => is_array($collection) && is_array($collection[0] ?? null)
                    ? array_slice(array_keys($collection[0]), 0, 20)
                    : [],
                'status' => $result['status'] ?? null,
                'error' => isset($result['error']) ? mb_substr((string) $result['error'], 0, 300) : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        }, array_slice($evidence->apiTools(), -12));

        return ['documents' => $documents, 'tool_results' => $tools];
    }

    /** @param list<mixed>|null $collection @param list<string> $fields @return list<array<string,mixed>> */
    private function identifierSamples(?array $collection, array $fields): array
    {
        if ($collection === null || $fields === []) {
            return [];
        }
        $samples = [];
        foreach (array_slice($collection, 0, 3) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sample = [];
            foreach ($fields as $field) {
                $value = $row[$field] ?? null;
                if (is_scalar($value) || $value === null) {
                    $sample[$field] = $value;
                }
            }
            if ($sample !== []) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    private function collection(array $result, ?string $path): ?array
    {
        if ($path === '$') {
            return array_is_list($result) ? $result : null;
        }
        if (is_string($path) && $path !== '') {
            $value = data_get($result, $path);
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }
        foreach ($result as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @param list<mixed>|null $collection @return array<string,mixed>|null */
    private function recordPreview(array $result, ?array $collection): ?array
    {
        if ($collection !== null) {
            return null;
        }

        $candidate = $result['data'] ?? data_get($result, 'artifact.structuredContent.data');
        if (! is_array($candidate) || array_is_list($candidate)) {
            return null;
        }

        return $this->previewArray($candidate);
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function previewArray(array $value, int $depth = 0): array
    {
        $preview = [];
        foreach (array_slice($value, 0, 12, true) as $key => $nested) {
            $preview[$key] = $this->previewValue($nested, $depth + 1);
        }

        return $preview;
    }

    private function previewValue(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 180);
        }
        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? $value : get_debug_type($value);
        }
        if ($depth >= 2) {
            return array_is_list($value)
                ? ['count' => count($value)]
                : ['keys' => array_slice(array_keys($value), 0, 12)];
        }
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $nested): mixed => $this->previewValue($nested, $depth + 1),
                array_slice($value, 0, 5),
            );
        }

        return $this->previewArray($value, $depth);
    }

    private function bounded(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 300);
        }
        if (! is_array($value) || $depth >= 3) {
            return $value;
        }
        $out = [];
        foreach (array_slice($value, 0, 12, true) as $key => $nested) {
            $out[$key] = $this->bounded($nested, $depth + 1);
        }

        return $out;
    }
}
