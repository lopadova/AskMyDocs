<?php

declare(strict_types=1);

namespace App\Services\Chat\Reasoning;

/** Strict model output is still untrusted. Context references must be server-known. */
final class FocusContract
{
    public static function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $focus = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['topic', 'identifiers', 'aspect', 'fields'], 'properties' => [
                'topic' => ['type' => 'string'], 'identifiers' => $strings,
                'aspect' => ['type' => 'string'], 'fields' => $strings + ['description' => 'Use ["*"] for general information/all details. For a specific attribute use canonical machine keys such as status, trackingCode, name; do not translate keys or invent extra attributes. Use [] when no structured attribute is requested.'],
            ]];
        $subquestion = $focus;
        $subquestion['required'] = [...$focus['required'], 'question', 'kb_queries'];
        $subquestion['properties'] += [
            'question' => ['type' => 'string', 'description' => 'A standalone question in the user language for ONLY this request, with resolved references.'],
            'kb_queries' => $strings + ['description' => '1-3 independent KB searches serving ONLY this question.'],
        ];
        return [
            'transition' => ['type' => 'string', 'enum' => ['new', 'continue', 'switch', 'correct', 'recap', 'provenance']],
            'focus' => $focus,
            'subquestions' => ['type' => 'array', 'items' => $subquestion, 'description' => 'Decompose unrelated requests into separate standalone questions, including focus. Keep dependent steps for one request together. Never copy previous subquestions.'],
            'resolved_references' => $strings + ['description' => 'Every identifier used in focus or subquestions but not literally typed now MUST be listed here, and MUST be in known_identifiers.'],
            'needs_clarification' => ['type' => 'boolean'],
            'clarification' => ['type' => 'string'],
        ];
    }

    public static function validate(array $data, array $known, array $literal): array
    {
        if (! in_array($data['transition'] ?? null, self::schema()['transition']['enum'], true)
            || ! is_bool($data['needs_clarification'] ?? null) || ! is_string($data['clarification'] ?? null)
            || mb_strlen($data['clarification']) > 500 || ! is_array($data['subquestions'] ?? null)
            || ! array_is_list($data['subquestions']) || count($data['subquestions']) < 1 || count($data['subquestions']) > 10) {
            throw new \UnexpectedValueException('Invalid focus contract.');
        }
        $resolved = self::strings($data['resolved_references'] ?? null);
        if (array_diff($resolved, $known) !== []) {
            throw new \UnexpectedValueException('Unregistered context reference.');
        }
        foreach ([$data['focus'], ...$data['subquestions']] as $focus) {
            if (! is_array($focus) || ! in_array(count($focus), [4, 6], true) || ! is_string($focus['topic'] ?? null)
                || ! is_string($focus['aspect'] ?? null) || mb_strlen($focus['topic']) > 300 || mb_strlen($focus['aspect']) > 300
                || array_diff(self::strings($focus['identifiers'] ?? null), [...$literal, ...$resolved]) !== []) {
                throw new \UnexpectedValueException('Invalid focus reference.');
            }
            self::strings($focus['fields'] ?? null);
            if (count($focus) === 6 && (! self::safeQuery($focus['question'] ?? null)
                || ! is_array($focus['kb_queries'] ?? null) || ! array_is_list($focus['kb_queries'])
                || count($focus['kb_queries']) < 1 || count($focus['kb_queries']) > 3
                || array_filter($focus['kb_queries'], fn ($query) => ! self::safeQuery($query)))) {
                throw new \UnexpectedValueException('Invalid research question.');
            }
        }
        $data['subquestions'] = self::activeSubquestions($data['focus'], $data['subquestions']);
        if (count($data['subquestions']) > 10) {
            throw new \UnexpectedValueException('Too many active subquestions.');
        }
        // Older snapshots (and a restored primary focus omitted by the model)
        // still need a standalone task. Reuse only already-validated searches
        // for this entity, never the combined parent intent or a sibling's query.
        $allIds = array_merge(...array_column($data['subquestions'], 'identifiers'));
        foreach ($data['subquestions'] as &$sub) {
            if (isset($sub['question'], $sub['kb_queries'])) {
                continue;
            }
            $ids = $sub['identifiers'];
            $otherIds = array_diff($allIds, $ids);
            $queries = $ids === [] ? [] : array_values(array_filter($data['kb_queries'] ?? [], fn ($query) => self::safeQuery($query)
                && ! array_filter($ids, fn ($id) => ! EvidenceProjector::contains($query, $id))
                && ! array_filter($otherIds, fn ($id) => EvidenceProjector::contains($query, $id))));
            $sub['question'] = $queries[0] ?? trim(implode(' ', [$sub['topic'], implode(' ', $ids), $sub['aspect']]));
            $sub['kb_queries'] = array_slice($queries ?: [$sub['question']], 0, 3);
        }
        unset($sub);
        return array_intersect_key($data, self::schema());
    }

    private static function safeQuery(mixed $query): bool
    {
        return is_string($query) && trim($query) !== '' && mb_strlen($query) <= 500
            && ! preg_match('~https?://|\bcurl\b|\b(?:call|invoke|execute|chiama|esegui)\s+(?:an?\s+|un\s+)?(?:mcp|api|tool|strumento)\b~iu', $query);
    }

    /** The primary focus is a request too, not merely a heading for secondary requests. */
    public static function activeSubquestions(array $focus, array $subquestions): array
    {
        if ($focus === []) {
            return $subquestions;
        }
        foreach ($subquestions as $subquestion) {
            $focusIds = $focus['identifiers'] ?? [];
            $subIds = $subquestion['identifiers'] ?? [];
            if ($focusIds == $subIds && ($focus['topic'] ?? '') === ($subquestion['topic'] ?? '')
                && ($focus['aspect'] ?? '') === ($subquestion['aspect'] ?? '')) {
                return $subquestions;
            }
        }
        return [$focus, ...$subquestions];
    }

    private static function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 12
            || array_filter($value, fn ($v) => ! is_string($v) || $v === '' || mb_strlen($v) > 120)) {
            throw new \UnexpectedValueException('Invalid focus list.');
        }
        return $value;
    }
}
