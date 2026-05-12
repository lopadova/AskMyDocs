<?php

declare(strict_types=1);

namespace App\Services\TabularReview;

use App\Ai\AiManager;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\TabularCell;
use App\Models\TabularReview;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\TabularReview\CellFlag;
use App\Support\TabularReview\CellStatus;
use App\Support\TabularReview\FormatType;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * v4.7/W1 — Batch tabular-review extractor.
 *
 * For one (review, document) pair the extractor:
 *
 *   1. Walks every column.
 *   2. Honours the JSON_PATH / frontmatter-key shortcut (v4.5/W5.5):
 *      when the column has `format: json_path` AND `json_path` resolves
 *      against the document's chunk metadata, emit the cell directly
 *      with `flag = grey` (sourced-from-metadata) — no LLM call, free.
 *   3. For the remaining columns, fetches top-K chunks via
 *      {@see KbSearchService::searchWithContext()} per column prompt,
 *      union'd into the prompt context.
 *   4. Issues ONE multi-column LLM call (Mike's pattern) that emits a
 *      newline-delimited JSON object per column.
 *   5. Parses + upserts every cell. Returns the list of persisted cells.
 *   6. Streams progress via the `$onCell` callback so SSE-based clients
 *      can paint cells as they land (the streaming HTTP transport
 *      lives in W3 — this hook makes it wire-able).
 *
 * R14: a column with no chunks above the relevance threshold or a JSON
 * line missing from the LLM response produces a cell with
 * `{flag: red, summary: null, reasoning: 'No evidence ...'}` and
 * `status: failed`. Empty 200 is impossible — every column gets an
 * outcome.
 *
 * R30: every query that touches `tabular_*` is scoped via
 * `forTenant($ctx->current())`. The KB search itself runs through
 * KbSearchService which honours the same TenantContext.
 *
 * R23: format dispatch lives in the FormatType enum — single source of
 * truth, no overlapping `supports()` predicate to worry about.
 */
final class TabularReviewExtractor
{
    private const TOP_K_CHUNKS = 8;
    private const MIN_SIMILARITY = 0.30;

    public function __construct(
        private readonly AiManager $ai,
        private readonly KbSearchService $search,
        private readonly TenantContext $ctx,
    ) {}

    /**
     * Extract every cell for the given (review, doc) pair.
     *
     * The optional `$onCell` callback receives each `TabularCell` as
     * soon as it is persisted; W3's SSE controller wires this to push
     * `cell` events to the FE Glide grid.
     *
     * @return list<TabularCell>
     */
    public function extract(
        TabularReview $review,
        KnowledgeDocument $doc,
        ?\Closure $onCell = null,
    ): array {
        $tenant = $this->ctx->current();
        $columns = $this->normaliseColumns($review->columns_config ?? []);

        if ($columns === []) {
            return [];
        }

        // Group columns by extraction path: shortcut (no LLM) vs LLM batch.
        $shortcutColumns = [];
        $llmColumns = [];
        foreach ($columns as $idx => $col) {
            $format = $col['format'];
            if ($format->isLlmFree() && $col['json_path'] !== null) {
                $shortcutColumns[$idx] = $col;
                continue;
            }
            $llmColumns[$idx] = $col;
        }

        $persisted = [];

        // ── Shortcut path ───────────────────────────────────────────
        foreach ($shortcutColumns as $idx => $col) {
            $value = $this->lookupJsonPath($doc, $col['json_path']);
            if ($value === null) {
                $cell = $this->persistFailure(
                    $tenant,
                    $review,
                    $doc,
                    $idx,
                    'No value found at JSON path '.$col['json_path'].'.',
                );
            } else {
                $cell = $this->persistCell(
                    $tenant,
                    $review,
                    $doc,
                    $idx,
                    status: CellStatus::READY,
                    content: [
                        'summary' => (string) $value,
                        'flag' => CellFlag::GREY->value,
                        'reasoning' => 'Sourced from document metadata at '.$col['json_path'].'.',
                        'citations' => [],
                    ],
                    flag: CellFlag::GREY,
                );
            }
            $persisted[] = $cell;
            $onCell?->__invoke($cell);
        }

        if ($llmColumns === []) {
            return $persisted;
        }

        // ── LLM batch path ──────────────────────────────────────────
        $chunkContext = $this->collectChunksForColumns($doc, $llmColumns);

        if ($chunkContext['chunks'] === []) {
            // No evidence at all → refuse every LLM column loudly (R14).
            foreach ($llmColumns as $idx => $col) {
                $cell = $this->persistFailure(
                    $tenant,
                    $review,
                    $doc,
                    $idx,
                    'No evidence found in this document above the relevance threshold.',
                );
                $persisted[] = $cell;
                $onCell?->__invoke($cell);
            }
            return $persisted;
        }

        $systemPrompt = $this->buildSystemPrompt($llmColumns);
        $userPrompt = $this->buildUserPrompt($llmColumns, $chunkContext);

        try {
            $response = $this->ai->chat($systemPrompt, $userPrompt, [
                'temperature' => 0.1,
                'max_tokens' => 1200,
            ]);
            $parsed = $this->parseLlmResponse($response->content);
        } catch (\Throwable $e) {
            Log::warning('TabularReviewExtractor LLM call failed', [
                'review_id' => $review->id,
                'document_id' => $doc->id,
                'message' => $e->getMessage(),
            ]);
            foreach ($llmColumns as $idx => $col) {
                // The exception message is logged above with full context.
                // Do NOT surface it via the persisted cell — the value is
                // returned to API consumers via `show()` and may contain
                // internal hostnames / vendor URLs / stack hints.
                $cell = $this->persistFailure(
                    $tenant,
                    $review,
                    $doc,
                    $idx,
                    'Extraction failed: provider error. See application log for details.',
                );
                $persisted[] = $cell;
                $onCell?->__invoke($cell);
            }
            return $persisted;
        }

        foreach ($llmColumns as $idx => $col) {
            $row = $parsed[$idx] ?? null;
            if ($row === null) {
                $cell = $this->persistFailure(
                    $tenant,
                    $review,
                    $doc,
                    $idx,
                    'Model did not return a result for this column.',
                );
                $persisted[] = $cell;
                $onCell?->__invoke($cell);
                continue;
            }

            $summary = isset($row['summary']) ? (string) $row['summary'] : null;
            if ($summary === null || trim($summary) === '') {
                $cell = $this->persistFailure(
                    $tenant,
                    $review,
                    $doc,
                    $idx,
                    'No evidence found in retrieved context for column "'.$col['name'].'".',
                );
                $persisted[] = $cell;
                $onCell?->__invoke($cell);
                continue;
            }

            $flag = CellFlag::tryFrom((string) ($row['flag'] ?? CellFlag::GREEN->value)) ?? CellFlag::GREEN;
            $citations = $this->normaliseCitations($row['citations'] ?? []);

            $cell = $this->persistCell(
                $tenant,
                $review,
                $doc,
                $idx,
                status: CellStatus::READY,
                content: [
                    'summary' => $summary,
                    'flag' => $flag->value,
                    'reasoning' => isset($row['reasoning']) ? (string) $row['reasoning'] : '',
                    'citations' => $citations,
                ],
                flag: $flag,
            );
            $persisted[] = $cell;
            $onCell?->__invoke($cell);
        }

        return $persisted;
    }

    /**
     * Normalise a `columns_config` payload into a typed list keyed by
     * the column's `index` position. Out-of-range / malformed entries
     * are dropped — the FormRequest validates upstream so this is
     * defence-in-depth.
     *
     * @param  array<int, mixed>  $raw
     * @return array<int, array{name: string, prompt: string, format: FormatType, enum_values: list<string>, json_path: ?string}>
     */
    private function normaliseColumns(array $raw): array
    {
        $out = [];
        foreach (array_values($raw) as $i => $col) {
            if (! is_array($col)) {
                continue;
            }
            $name = isset($col['name']) ? trim((string) $col['name']) : '';
            $prompt = isset($col['prompt']) ? trim((string) $col['prompt']) : '';
            $formatRaw = isset($col['format']) ? (string) $col['format'] : FormatType::TEXT->value;
            $format = FormatType::tryFrom($formatRaw) ?? FormatType::TEXT;

            $enumValues = [];
            if (isset($col['enum_values']) && is_array($col['enum_values'])) {
                foreach ($col['enum_values'] as $v) {
                    if (is_string($v) && trim($v) !== '') {
                        $enumValues[] = $v;
                    }
                }
            }

            $jsonPath = isset($col['json_path']) && is_string($col['json_path']) && trim($col['json_path']) !== ''
                ? trim($col['json_path'])
                : null;

            if ($name === '') {
                continue;
            }

            $out[$i] = [
                'name' => $name,
                'prompt' => $prompt,
                'format' => $format,
                'enum_values' => $enumValues,
                'json_path' => $jsonPath,
            ];
        }
        return $out;
    }

    /**
     * Resolve a JSONPath-style key (`$.foo.bar`) against the document's
     * chunk metadata, scanning chunks in chunk_order until a match is
     * found. v4.5/W5.5 chunkers stash rich frontmatter under
     * `chunk.metadata` so this lookup is the path to the
     * frontmatter-shortcut win.
     */
    private function lookupJsonPath(KnowledgeDocument $doc, string $path): ?string
    {
        $segments = $this->parseJsonPath($path);
        if ($segments === []) {
            return null;
        }

        $tenant = $this->ctx->current();
        $chunks = KnowledgeChunk::query()
            ->forTenant($tenant)
            ->where('knowledge_document_id', $doc->id)
            ->orderBy('chunk_order')
            ->limit(20)
            ->get();

        foreach ($chunks as $chunk) {
            $value = $this->descend($chunk->metadata ?? [], $segments);
            if ($value !== null) {
                return $this->stringifyValue($value);
            }
        }

        // Fall back to the document's own metadata column.
        $docMeta = is_array($doc->metadata ?? null) ? $doc->metadata : [];
        $value = $this->descend($docMeta, $segments);

        if ($value === null) {
            return null;
        }

        return $this->stringifyValue($value);
    }

    /**
     * Convert a JSON-path lookup result into a string. Non-scalars go
     * through `json_encode` with `JSON_THROW_ON_ERROR` so an encoding
     * failure raises rather than returning the literal string "false"
     * — which would otherwise leak as a cell value.
     */
    private function stringifyValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            return $encoded === false ? null : $encoded;
        } catch (\JsonException $e) {
            Log::warning('TabularReviewExtractor json_path encode failed', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function parseJsonPath(string $path): array
    {
        // Accept `$.foo.bar`, `foo.bar`, `$['foo']['bar']` — strip the
        // root marker and bracket notation, then split on dots.
        $path = preg_replace('/^\\$\.?/', '', $path) ?? $path;
        $path = (string) preg_replace_callback(
            "/\\[\\s*['\"]?([^'\"\\]]+)['\"]?\\s*\\]/",
            static fn ($m) => '.'.$m[1],
            $path,
        );
        $path = trim($path, '.');
        if ($path === '') {
            return [];
        }
        return array_values(array_filter(explode('.', $path), static fn ($p) => $p !== ''));
    }

    /**
     * @param  array<mixed>  $haystack
     * @param  list<string>  $segments
     */
    private function descend(array $haystack, array $segments): mixed
    {
        $current = $haystack;
        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    /**
     * Fetch top-K chunks for each column's prompt, union by chunk_id.
     * Returns a chunk-context payload the LLM prompt can render.
     *
     * @param  array<int, array{name: string, prompt: string, format: FormatType, enum_values: list<string>, json_path: ?string}>  $columns
     * @return array{chunks: list<array{id: int|string, heading: string, text: string}>, doc_id: int}
     */
    private function collectChunksForColumns(KnowledgeDocument $doc, array $columns): array
    {
        $filters = new RetrievalFilters(
            projectKeys: $doc->project_key !== null ? [$doc->project_key] : [],
            docIds: [(int) $doc->id],
        );

        $byId = [];
        foreach ($columns as $col) {
            $query = $col['prompt'] !== '' ? $col['prompt'] : $col['name'];
            try {
                $result = $this->search->searchWithContext(
                    $query,
                    $doc->project_key,
                    self::TOP_K_CHUNKS,
                    self::MIN_SIMILARITY,
                    $filters,
                );
            } catch (\Throwable $e) {
                Log::warning('TabularReviewExtractor search failed', [
                    'document_id' => $doc->id,
                    'column' => $col['name'],
                    'message' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($result->primary as $chunk) {
                $id = $chunk->id ?? spl_object_hash($chunk);
                if (isset($byId[$id])) {
                    continue;
                }
                $byId[$id] = [
                    'id' => $id,
                    'heading' => (string) ($chunk->heading_path ?? ''),
                    'text' => (string) ($chunk->chunk_text ?? ''),
                ];
            }
        }

        return [
            'chunks' => array_values($byId),
            'doc_id' => (int) $doc->id,
        ];
    }

    /**
     * @param  array<int, array{name: string, prompt: string, format: FormatType, enum_values: list<string>, json_path: ?string}>  $columns
     */
    private function buildSystemPrompt(array $columns): string
    {
        $lines = [
            'You are an information-extraction engine for a tabular-review tool.',
            'You will be given context CHUNKS from a single document and a list of COLUMNS.',
            'For EACH column, output ONE line of JSON with this shape:',
            '{"column_index": <int>, "summary": <string>, "flag": "green"|"grey"|"yellow"|"red", "reasoning": <string>, "citations": [{"chunk_id": <id>, "quote": <string>}]}',
            '',
            'Rules:',
            '- Output one JSON object per column, one per line.',
            '- Do NOT wrap the output in markdown fences.',
            '- If no evidence supports a column, set "flag": "red" and "summary": null.',
            '- Use "green" for a single confident chunk, "yellow" for conflicting evidence, "grey" when present but ambiguous.',
            '- "citations" MUST list the chunk_id values you grounded the answer on.',
            '',
            'Columns:',
        ];

        foreach ($columns as $idx => $col) {
            $suffix = $col['format']->promptSuffix($col['enum_values']);
            $lines[] = sprintf(
                '  - column_index=%d  name="%s"  prompt="%s"  format=%s. %s',
                $idx,
                $col['name'],
                $col['prompt'] === '' ? $col['name'] : $col['prompt'],
                $col['format']->value,
                $suffix,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{name: string, prompt: string, format: FormatType, enum_values: list<string>, json_path: ?string}>  $columns
     * @param  array{chunks: list<array{id: int|string, heading: string, text: string}>, doc_id: int}  $context
     */
    private function buildUserPrompt(array $columns, array $context): string
    {
        $lines = ['Document chunks:'];
        foreach ($context['chunks'] as $chunk) {
            $lines[] = sprintf(
                "[chunk_id=%s] %s\n%s\n",
                (string) $chunk['id'],
                $chunk['heading'],
                $chunk['text'],
            );
        }
        $lines[] = '';
        $lines[] = 'Now produce one JSON line per column, in order. Do not add any other text.';
        return implode("\n", $lines);
    }

    /**
     * Parse the LLM's newline-delimited JSON response into a map keyed
     * by `column_index`. Lines that fail JSON parsing are skipped.
     *
     * @return array<int, array{summary?: ?string, flag?: ?string, reasoning?: ?string, citations?: array<mixed>}>
     */
    private function parseLlmResponse(string $content): array
    {
        $out = [];
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        // Strip markdown fences if the model snuck them in.
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content) ?? $content;

        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }
            if (! isset($decoded['column_index']) || ! is_numeric($decoded['column_index'])) {
                continue;
            }
            $idx = (int) $decoded['column_index'];
            $out[$idx] = $decoded;
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return list<array{chunk_id: string, quote: string}>
     */
    private function normaliseCitations(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $c) {
            if (! is_array($c)) {
                continue;
            }
            $chunkId = $c['chunk_id'] ?? $c['id'] ?? null;
            $quote = $c['quote'] ?? null;
            if ($chunkId === null) {
                continue;
            }
            $out[] = [
                'chunk_id' => (string) $chunkId,
                'quote' => is_string($quote) ? $quote : '',
            ];
        }
        return $out;
    }

    /**
     * Persist a cell idempotently. Uses Eloquent `updateOrCreate` so the
     * lookup+write hits the DB as a single transactional sequence —
     * concurrent `generate` / `regenerate-cell` calls on the same
     * (tenant, review, doc, column) tuple can't race past each other
     * into a unique-constraint violation, because the composite UNIQUE
     * `(tenant_id, review_id, document_id, column_index)` makes the
     * second writer's CREATE collapse into an UPDATE.
     *
     * @param  array<string, mixed>  $content
     */
    private function persistCell(
        string $tenant,
        TabularReview $review,
        KnowledgeDocument $doc,
        int $columnIndex,
        CellStatus $status,
        array $content,
        ?CellFlag $flag,
    ): TabularCell {
        return TabularCell::updateOrCreate(
            [
                'tenant_id' => $tenant,
                'review_id' => $review->id,
                'document_id' => $doc->id,
                'column_index' => $columnIndex,
            ],
            [
                'content' => $content,
                'status' => $status->value,
                'flag' => $flag?->value,
                'generated_at' => Carbon::now(),
            ],
        );
    }

    private function persistFailure(
        string $tenant,
        TabularReview $review,
        KnowledgeDocument $doc,
        int $columnIndex,
        string $reason,
    ): TabularCell {
        return $this->persistCell(
            $tenant,
            $review,
            $doc,
            $columnIndex,
            status: CellStatus::FAILED,
            content: [
                'summary' => null,
                'flag' => CellFlag::RED->value,
                'reasoning' => $reason,
                'citations' => [],
            ],
            flag: CellFlag::RED,
        );
    }
}
