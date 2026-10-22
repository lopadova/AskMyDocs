<?php

declare(strict_types=1);

namespace App\Services\Chat\Reasoning;

/** Projects original evidence, never model-provided quotations or wrapper search terms. */
final class EvidenceProjector
{
    public function forPrompt(array $evidence): array
    {
        foreach ($evidence['documents'] ?? [] as $index => $document) {
            foreach ($document['evidence'] ?? [] as $chunkIndex => $chunk) {
                if (! is_int($document['document_id'] ?? null) || ! is_string($chunk['evidence_hash'] ?? null)) {
                    continue;
                }
                $evidence['documents'][$index]['evidence'][$chunkIndex]['claim_source'] = [
                    'document_id' => $document['document_id'], 'tool_execution_id' => null,
                    'evidence_hash' => $chunk['evidence_hash'],
                ];
                $evidence['documents'][$index]['evidence'][$chunkIndex]['source_ref'] = $this->sourceRef('d', $document['document_id'], $chunk['evidence_hash']);
            }
        }
        foreach ($evidence['api_tools'] ?? [] as $index => $tool) {
            if (is_int($tool['execution_id'] ?? null) && is_string($tool['evidence_hash'] ?? null)) {
                $evidence['api_tools'][$index]['claim_source'] = [
                    'document_id' => null, 'tool_execution_id' => $tool['execution_id'],
                    'evidence_hash' => $tool['evidence_hash'],
                ];
                $evidence['api_tools'][$index]['source_ref'] = $this->sourceRef('t', $tool['execution_id'], $tool['evidence_hash']);
            }
            $records = self::records($tool);
            if ($records !== []) {
                $evidence['api_tools'][$index]['result'] = ['records' => array_column($records, 'record'),
                    'record_paths' => array_column($records, 'path'), 'retrieved_at' => $tool['retrieved_at'] ?? null];
            }
        }
        return $evidence;
    }

    /**
     * Recover omitted keys from an exact host reference or UNIQUE current hash.
     * Never interpret malformed model keys, overwrite explicit identities, search
     * the database, or borrow evidence from another task. bind() and the validator
     * still enforce scope, record identity and literal membership afterwards.
     */
    public function resolveSourceIdentity(array $evidence, array $claim): array
    {
        $hash = $claim['evidence_hash'] ?? null;
        if (($claim['document_id'] ?? null) === null && ($claim['tool_execution_id'] ?? null) === null
            && $hash === '' && is_string($claim['source_ref'] ?? null)) {
            $matches = [];
            foreach ($evidence['documents'] ?? [] as $document) {
                foreach ($document['evidence'] ?? [] as $chunk) {
                    if (is_int($document['document_id'] ?? null) && is_string($chunk['evidence_hash'] ?? null)
                        && $claim['source_ref'] === $this->sourceRef('d', $document['document_id'], $chunk['evidence_hash'])) {
                        $matches['d:'.$document['document_id'].':'.$chunk['evidence_hash']] = [
                            'document_id' => $document['document_id'], 'tool_execution_id' => null, 'evidence_hash' => $chunk['evidence_hash']];
                    }
                }
            }
            foreach ($evidence['api_tools'] ?? [] as $tool) {
                if (is_int($tool['execution_id'] ?? null) && is_string($tool['evidence_hash'] ?? null)
                    && $claim['source_ref'] === $this->sourceRef('t', $tool['execution_id'], $tool['evidence_hash'])) {
                    $matches['t:'.$tool['execution_id'].':'.$tool['evidence_hash']] = [
                        'document_id' => null, 'tool_execution_id' => $tool['execution_id'], 'evidence_hash' => $tool['evidence_hash']];
                }
            }
            // Only an exact, unique reference in the CURRENT envelope resolves.
            // Explicit IDs/hashes are never overwritten. bind() still checks
            // scope, record identity and literal membership against the full hash.
            return count($matches) === 1 ? [...$claim, ...array_values($matches)[0]] : $claim;
        }
        if (($claim['document_id'] ?? null) !== null || ($claim['tool_execution_id'] ?? null) !== null
            || ! is_string($hash) || $hash === '') {
            return $claim;
        }
        $matches = [];
        foreach ($evidence['documents'] ?? [] as $document) {
            foreach ($document['evidence'] ?? [] as $chunk) {
                if (($chunk['evidence_hash'] ?? null) === $hash && is_int($document['document_id'] ?? null)) {
                    $matches['document:'.$document['document_id']] = ['document_id' => $document['document_id'], 'tool_execution_id' => null];
                }
            }
        }
        foreach ($evidence['api_tools'] ?? [] as $tool) {
            if (($tool['evidence_hash'] ?? null) === $hash && is_int($tool['execution_id'] ?? null)) {
                $matches['tool:'.$tool['execution_id']] = ['document_id' => null, 'tool_execution_id' => $tool['execution_id']];
            }
        }

        return count($matches) === 1 ? [...$claim, ...array_values($matches)[0]] : $claim;
    }

    private function sourceRef(string $kind, int $id, string $hash): string
    {
        return $kind.$id.'_'.substr($hash, 0, 12);
    }

    /** Table rows stay literal; match exact requested identities, never a wrapper query echo. */
    public function forTable(array $tools, array $understanding, string $tenant, string $project): array
    {
        $ids = $understanding['focus']['identifiers'] ?? [];
        $selected = [];
        foreach ($tools as $tool) {
            if ((isset($tool['tenant_id']) && $tool['tenant_id'] !== $tenant)
                || (isset($tool['result']['companyKey']) && $tool['result']['companyKey'] !== $project)) {
                continue;
            }
            $records = self::records($tool);
            if ($records === []) {
                continue;
            }
            $rows = array_values(array_filter(array_column($records, 'record'), fn ($record) => $ids === []
                || array_intersect($ids, self::identities($record, $tool['presentation']['identity_fields'] ?? [])) !== []
                || array_filter($record, fn ($value, $key) => preg_match('/(?:Id|Ids)$/', (string) $key)
                    && array_intersect($ids, is_array($value) ? $value : [(string) $value]) !== [], ARRAY_FILTER_USE_BOTH)));
            if ($rows !== []) {
                $selected[] = [...$tool, 'result' => ['records' => $rows, 'total' => count($rows)],
                    'presentation' => [...($tool['presentation'] ?? []), 'collection_path' => 'records']];
            }
        }
        return $selected;
    }

    public static function contains(string $text, string $identifier): bool
    {
        return $identifier !== '' && preg_match('/(?<![\pL\pN_-])'.preg_quote($identifier, '/').'(?![\pL\pN_-])/iu', $text) === 1;
    }

    public static function records(array $tool): array
    {
        $result = $tool['result'] ?? [];
        $path = data_get($tool, 'presentation.collection_path');
        foreach (array_filter([$path, 'records', 'artifact.structuredContent.records', 'data.records', 'items', 'documents', 'orders', 'shipments', 'customers', 'products', 'data']) as $candidate) {
            $rows = $candidate === '$' ? $result : data_get($result, $candidate);
            if (is_array($rows) && array_is_list($rows) && $rows !== [] && ! array_filter($rows, fn ($r) => ! is_array($r))) {
                return array_map(fn ($row, $i) => ['record' => $row, 'path' => $candidate.'.'.$i], $rows, array_keys($rows));
            }
        }
        if (is_array($result) && isset($result['id'])) {
            return [['record' => $result, 'path' => '$']];
        }
        return [];
    }

    public static function identities(array $record, array $declaredFields = []): array
    {
        // Foreign keys and emailEvidence are not the identity of the returned record.
        $fields = $declaredFields !== [] ? array_values(array_filter($declaredFields,
            static fn ($key) => is_string($key) && preg_match('/^[a-zA-Z0-9_-]+$/', $key)))
            : ['id', 'code', 'trackingCode', 'sku', 'orderNumber', 'customerCode', 'productCode'];
        return array_values(array_filter(array_map(fn ($key) => isset($record[$key]) && is_scalar($record[$key])
            ? (string) $record[$key] : null, $fields)));
    }

    public function bind(array $evidence, array $claim, array $ids, string $tenant, string $project): array
    {
        $hash = $claim['evidence_hash'] ?? '';
        $doc = $claim['document_id'] ?? null;
        $execution = $claim['tool_execution_id'] ?? null;
        if (! is_string($hash) || $hash === '' || ($doc === null) === ($execution === null)) {
            return ['reason' => 'invalid_source_identity'];
        }
        foreach ($doc !== null ? ($evidence['documents'] ?? []) : ($evidence['api_tools'] ?? []) as $source) {
            if (($doc !== null ? ($source['document_id'] ?? null) : ($source['execution_id'] ?? null)) != ($doc ?? $execution)) {
                continue;
            }
            if ((isset($source['tenant_id']) && $source['tenant_id'] !== $tenant)
                || (isset($source['project_key']) && $source['project_key'] !== $project)
                || (isset($source['result']['companyKey']) && $source['result']['companyKey'] !== $project)) {
                return ['reason' => 'source_scope_mismatch'];
            }
            if ($doc !== null) {
                foreach ($source['evidence'] ?? [] as $chunk) {
                    if (($chunk['evidence_hash'] ?? null) !== $hash) {
                        continue;
                    }
                    $text = $chunk['content'] ?? '';
                    if (! is_string($text) || ! $this->documentSubjectsMatch($evidence, $source, $ids)) {
                        return ['reason' => 'entity_mismatch'];
                    }
                    return ['quote' => $text, 'record' => null, 'path' => $chunk['chunk_id'] ?? null,
                        'source_title' => $chunk['source_title'] ?? $source['title'] ?? '',
                        'complete_source' => $chunk['complete_source'] ?? false];
                }
                return ['reason' => 'source_hash_mismatch'];
            }
            if (! hash_equals((string) ($source['evidence_hash'] ?? ''), $hash)) {
                return ['reason' => 'source_hash_mismatch'];
            }
            $records = self::records($source);
            $matches = array_values(array_filter($records, static function ($entry) use ($ids, $claim, $source): bool {
                if (isset($claim['record_path']) && $claim['record_path'] !== $entry['path']) {
                    return false;
                }
                return $ids === [] || array_intersect($ids, self::identities($entry['record'], $source['presentation']['identity_fields'] ?? [])) !== [];
            }));
            if (count($matches) !== 1) {
                return ['reason' => $matches === [] ? 'entity_mismatch' : 'ambiguous_record'];
            }
            return ['quote' => json_encode($matches[0]['record'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'record' => $matches[0]['record'], 'path' => $matches[0]['path'],
                'identity_fields' => $source['presentation']['identity_fields'] ?? [],
                'field_aliases' => $source['presentation']['field_aliases'] ?? [],
                'observed_at' => $source['retrieved_at'] ?? null];
        }
        return ['reason' => 'source_not_in_authorized_evidence'];
    }

    private function documentSubjectsMatch(array $evidence, array $source, array $identifiers): bool
    {
        $matches = static fn ($document, $identifier) => self::contains($document['title'] ?? '', $identifier)
            || array_filter($document['evidence'] ?? [], fn ($chunk) => self::contains(($chunk['source_title'] ?? '')."\n".($chunk['content'] ?? ''), $identifier)
                || in_array(hash('sha256', $identifier), $chunk['verified_identifier_hashes'] ?? [], true));
        $opaque = array_filter($identifiers, fn ($id) => preg_match('/^[\pL\pN_-]+$/u', $id)
            && preg_match('/[\pN_-]/u', $id));
        if (array_filter($opaque, fn ($id) => ! $matches($source, $id))) {
            return false;
        }
        $prose = array_diff($identifiers, $opaque);
        // When this request has an exact subject anchor, a similar unrelated
        // case cannot supply its procedure. Prose names may also be actors or
        // attributes: do not demand that every name repeat in every reply.
        if ($prose !== [] && ! array_filter($prose, fn ($id) => $matches($source, $id))) {
            return ! array_filter($evidence['documents'] ?? [], fn ($document) => array_filter($prose, fn ($id) => $matches($document, $id)));
        }

        return true;
    }

    /** Include other current evidence about the same entity, even when the draft did not cite it. */
    public function alternatives(array $evidence, array $ids, string $quote, string $tenant, string $project, ?int $documentId = null): array
    {
        $alternatives = [];
        foreach ($evidence['documents'] ?? [] as $document) {
            // Cross-document context must share all requested anchors. One
            // common organization name cannot pull every customer case in.
            if (($document['document_id'] ?? null) !== $documentId && ($ids === []
                || array_filter($ids, fn ($id) => ! self::contains($document['title'] ?? '', $id)
                    && ! array_filter($document['evidence'] ?? [], fn ($chunk) => self::contains(($chunk['source_title'] ?? '')."\n".($chunk['content'] ?? ''), $id)
                        || in_array(hash('sha256', $id), $chunk['verified_identifier_hashes'] ?? [], true))))) {
                continue;
            }
            foreach ($document['evidence'] ?? [] as $chunk) {
                $bound = $this->bind($evidence, ['document_id' => $document['document_id'], 'evidence_hash' => $chunk['evidence_hash']], $ids, $tenant, $project);
                if (isset($bound['quote']) && $bound['quote'] !== $quote) {
                    $alternatives[hash('sha256', $bound['quote'])] = ['source' => $bound['quote'], 'document_id' => $document['document_id']];
                }
            }
        }
        foreach ($ids === [] ? [] : ($evidence['api_tools'] ?? []) as $tool) {
            foreach (self::records($tool) as $entry) {
                $bound = $this->bind($evidence, ['tool_execution_id' => $tool['execution_id'], 'evidence_hash' => $tool['evidence_hash'], 'record_path' => $entry['path']], $ids, $tenant, $project);
                if (isset($bound['quote']) && $bound['quote'] !== $quote) {
                    $alternatives[hash('sha256', $bound['quote'])] = ['source' => $bound['quote'], 'tool_execution_id' => $tool['execution_id'], 'retrieved_at' => $tool['retrieved_at'] ?? null];
                }
            }
        }
        return array_values($alternatives);
    }
}
