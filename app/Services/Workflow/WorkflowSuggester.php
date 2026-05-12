<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Ai\AiManager;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TabularReview\FormatType;
use App\Support\TenantContext;
use App\Support\Workflow\WorkflowPractice;
use App\Support\Workflow\WorkflowType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * v4.7/W2 — AI-suggested workflows from the tenant's own KB.
 *
 * The AskMyDocs differentiator over Mike: instead of forcing the user
 * to discover templates manually, sample the tenant's documents, ask
 * the LLM to surface workflow templates the user would actually want.
 *
 * R14: every failure path emits a structured refusal with `proposals=[]`
 * and an explanatory `meta.reason`. Never silent null.
 * R30: KB sampling is tenant-scoped via TenantContext.
 */
final class WorkflowSuggester
{
    /** Cache TTL in seconds (24 hours). */
    public const CACHE_TTL = 86_400;

    /** Stratified-sample size. */
    private const SAMPLE_SIZE = 50;

    /**
     * Oversampling factor for the recent-rows fetch in
     * {@see sampleDocuments()}. Pulls ~SAMPLE_SIZE * this many rows
     * so the in-memory stratification has enough material to give
     * every project_key a slice. Copilot iter 19: previously this
     * was a hard-coded `3` inside the method with the docblock
     * referencing a non-existent `SAMPLE_OVERSAMPLE` constant.
     */
    private const SAMPLE_OVERSAMPLE = 3;

    /**
     * Title char cap. Mirrored in the system prompt and enforced in
     * {@see validateProposal()}. Copilot iter 1 flagged the drift
     * between "max 80 chars" in the prompt vs `mb_substr(..., 200)`
     * in validation — both surfaces now reference this constant.
     */
    private const TITLE_MAX_CHARS = 80;

    /**
     * Per-column caps that mirror StoreWorkflowRequest /
     * FromProposalRequest so every suggested proposal is guaranteed
     * to round-trip through the save endpoints without a 422.
     * Copilot iter 7: previously the validator only checked the
     * format + json_path requirements; a chatty LLM could return
     * 80 columns or a 500-char name and the FE's "save" button
     * would fail on the very next request.
     */
    private const COLUMNS_MAX = 50;

    private const COLUMN_NAME_MAX_CHARS = 120;

    private const COLUMN_PROMPT_MAX_CHARS = 2000;

    private const COLUMN_JSON_PATH_MAX_CHARS = 200;

    private const ENUM_VALUE_MAX_CHARS = 120;

    private const ENUM_VALUES_MAX = 100;

    private const PROMPT_MD_MAX_CHARS = 20_000;

    public function __construct(
        private readonly AiManager $ai,
        private readonly MetadataPatternAnalyzer $analyzer,
        private readonly TenantContext $ctx,
    ) {}

    /**
     * @return array{
     *     proposals: list<array<string, mixed>>,
     *     meta: array{
     *         tenant_id: string,
     *         documents_analysed: int,
     *         cache_hit: bool,
     *         reason?: string,
     *     },
     * }
     */
    public function suggest(User $user, int $limit = 5, bool $forceRefresh = false): array
    {
        $tenant = $this->ctx->current();
        $limit = max(1, min(10, $limit));

        // Copilot iter 9: the cache key is intentionally tenant-scoped
        // (not user-scoped) so suggestions are shared across every
        // user in the tenant — same KB, same proposals, ONE LLM call
        // per 24h. `$user` is kept on the signature for two reasons:
        // (a) audit trails / future per-user filtering can be added
        // without a breaking change to call sites; (b) the controller
        // already type-hints the User so dropping the param would
        // force ugly casts in every consumer. The unused-local
        // pattern matches `AdminMetricsService::overview()` and is
        // accepted by Pint + static analysis.
        unset($user);

        $cacheKey = sprintf('workflow_suggester:%s:%d', $tenant, $limit);

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            // Copilot iter 12: validate the cached payload shape
            // before trusting it. A polluted cache key (different
            // version of the service, manual cache poison, type
            // collision) would otherwise turn a cache hit into a
            // 500 when we mutate `$cached['meta']['cache_hit']` on
            // a non-array. Treat malformed payloads as a miss and
            // re-derive.
            if (is_array($cached) && isset($cached['meta']) && is_array($cached['meta'])
                && isset($cached['proposals']) && is_array($cached['proposals'])) {
                $cached['meta']['cache_hit'] = true;
                return $cached;
            }
        }

        $documents = $this->sampleDocuments($tenant);
        if ($documents->isEmpty()) {
            return $this->refusal($tenant, 'No documents available for analysis.');
        }

        $patterns = $this->analyzer->analyze($documents);

        try {
            $response = $this->ai->chat(
                $this->systemPrompt(),
                $this->userPrompt($patterns, $limit),
                [
                    'temperature' => 0.4,
                    'max_tokens' => 1200,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('WorkflowSuggester: AI call failed', [
                'tenant_id' => $tenant,
                'message' => $e->getMessage(),
            ]);
            return $this->refusal($tenant, 'AI provider call failed.', $patterns['documents_analysed']);
        }

        $proposals = $this->parseProposals($response->content);
        if ($proposals === []) {
            return $this->refusal(
                $tenant,
                'AI returned no parseable proposals.',
                $patterns['documents_analysed'],
            );
        }

        // Copilot iter 3: hard-truncate to the caller's $limit AFTER
        // validation so a chatty LLM that returns 12 proposals when 5
        // were requested cannot blow the contract. The clamp at the
        // top of suggest() bounds the prompt; this enforces the same
        // ceiling on the validated output.
        if (count($proposals) > $limit) {
            $proposals = array_slice($proposals, 0, $limit);
        }

        $payload = [
            'proposals' => $proposals,
            'meta' => [
                'tenant_id' => $tenant,
                'documents_analysed' => $patterns['documents_analysed'],
                'cache_hit' => false,
            ],
        ];

        Cache::put($cacheKey, $payload, self::CACHE_TTL);

        return $payload;
    }

    /**
     * Sample up to SAMPLE_SIZE documents stratified by project_key.
     *
     * Copilot iter 2: the previous shape ran one query per project_key
     * which became N+1 as tenants accumulated projects. New shape:
     * fetch up to `SAMPLE_SIZE * SAMPLE_OVERSAMPLE` recent rows in ONE
     * query, then stratify in memory by project_key with a per-project
     * cap. The oversample factor (3) is large enough that a tenant
     * with up to ~SAMPLE_SIZE projects still gets at least one doc
     * per project on average, while the SQL stays a single bounded
     * SELECT regardless of project cardinality.
     */
    private function sampleDocuments(string $tenant): \Illuminate\Support\Collection
    {
        $oversample = self::SAMPLE_SIZE * self::SAMPLE_OVERSAMPLE;

        // Copilot iter 8: align with the rest of the KB read surface
        // (KbSearchService / GraphExpander / RejectedApproachInjector
        // all use `status != 'archived'`). The production ingest
        // pipeline writes `status='active'`, NOT `status='indexed'`,
        // so the previous strict equality filter would have returned
        // zero rows in production and always hit the empty-KB
        // refusal path. The negative-match shape admits every
        // non-archived state ('active', 'indexed' for tests,
        // pending, …) consistently with the rest of the codebase.
        $rows = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('status', '!=', 'archived')
            ->latest('id')
            ->limit($oversample)
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        $groups = $rows->groupBy('project_key');
        $projectCount = max(1, $groups->count());
        $perProject = max(1, (int) ceil(self::SAMPLE_SIZE / $projectCount));

        $stratified = collect();
        foreach ($groups as $group) {
            $stratified = $stratified->concat($group->take($perProject));
            if ($stratified->count() >= self::SAMPLE_SIZE) {
                break;
            }
        }

        // If we still have room (single-project tenant + small per-project
        // cap), backfill from the leftover pool.
        if ($stratified->count() < self::SAMPLE_SIZE) {
            $seen = $stratified->pluck('id')->all();
            $extra = $rows->reject(fn ($r) => in_array($r->id, $seen, true))
                ->take(self::SAMPLE_SIZE - $stratified->count());
            $stratified = $stratified->concat($extra);
        }

        return $stratified->take(self::SAMPLE_SIZE)->values();
    }

    /**
     * @param array<string, mixed> $patterns
     */
    private function userPrompt(array $patterns, int $limit): string
    {
        return sprintf(
            "Document metadata signature:\n%s\n\nPropose %d workflow templates. "
            ."Reply with ONE JSON array, no commentary.",
            json_encode($patterns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            $limit,
        );
    }

    private function systemPrompt(): string
    {
        $assistantValue = WorkflowType::Assistant->value;
        $tabularValue = WorkflowType::Tabular->value;
        $practiceList = implode(', ', WorkflowPractice::values());
        $titleCap = self::TITLE_MAX_CHARS;

        return <<<SYS
You are an assistant that proposes reusable workflow templates for a
knowledge-management tool, given a compact signature of the tenant's
documents. Each proposal carries:
  - title (max {$titleCap} chars)
  - type ("{$assistantValue}" or "{$tabularValue}")
  - prompt_md (the system prompt the workflow will run)
  - columns_config (array of {name, prompt, format} — required when type
    is "{$tabularValue}", null otherwise)
  - practice (one of: {$practiceList})
  - reasoning (1 sentence explaining why this template fits the KB)

Return ONE JSON array. No prose, no markdown fences, no preamble.
SYS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseProposals(string $raw): array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            return [];
        }

        $valid = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalised = $this->validateProposal($item);
            if ($normalised !== null) {
                $valid[] = $normalised;
            }
        }

        return $valid;
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>|null
     */
    private function validateProposal(array $proposal): ?array
    {
        // Copilot iter 14: strict type checks instead of `(string)` casts.
        // A misbehaving LLM that returned `title: []` would have been
        // cast to the literal string "Array" and emitted a PHP warning,
        // then potentially passed downstream validation as a junk
        // proposal. Reject non-strings up front.
        $title = isset($proposal['title']) && is_string($proposal['title'])
            ? trim($proposal['title'])
            : '';
        $type = isset($proposal['type']) && is_string($proposal['type'])
            ? $proposal['type']
            : '';
        $promptMd = isset($proposal['prompt_md']) && is_string($proposal['prompt_md'])
            ? $proposal['prompt_md']
            : '';
        $practice = isset($proposal['practice']) && is_string($proposal['practice'])
            ? $proposal['practice']
            : WorkflowPractice::Generic->value;
        $reasoning = isset($proposal['reasoning']) && is_string($proposal['reasoning'])
            ? $proposal['reasoning']
            : '';
        $columnsConfig = $proposal['columns_config'] ?? null;

        if ($title === '' || $promptMd === '') {
            return null;
        }

        if (! in_array($type, WorkflowType::values(), true)) {
            return null;
        }

        if (! in_array($practice, WorkflowPractice::values(), true)) {
            $practice = WorkflowPractice::Generic->value;
        }

        if ($type === WorkflowType::Tabular->value) {
            if (! is_array($columnsConfig) || $columnsConfig === []) {
                return null;
            }
            // Copilot iter 2/4/7: validate every column against the
            // exact constraints that StoreWorkflowRequest /
            // FromProposalRequest enforce — format ∈ FormatType,
            // json_path required for `format=json_path`, plus the
            // per-field length caps + column-count ceiling. Each
            // suggested proposal is then guaranteed to round-trip
            // through `/from-proposal` without a 422.
            $formats = FormatType::values();
            $jsonPathFormat = FormatType::JSON_PATH->value;
            $normalised = [];
            foreach ($columnsConfig as $col) {
                if (count($normalised) >= self::COLUMNS_MAX) {
                    break;
                }
                if (! is_array($col)) {
                    continue;
                }
                // Copilot iter 15: mirror the top-level proposal
                // strict-string guards on every per-column field so a
                // non-scalar `name: []` is rejected rather than
                // emitting a PHP warning and accidentally passing
                // validation as the string "Array".
                if (! isset($col['name']) || ! is_string($col['name'])) {
                    continue;
                }
                if (! isset($col['format']) || ! is_string($col['format'])) {
                    continue;
                }
                $name = trim($col['name']);
                $format = $col['format'];
                if ($name === '' || $format === '' || ! in_array($format, $formats, true)) {
                    continue;
                }
                if (mb_strlen($name) > self::COLUMN_NAME_MAX_CHARS) {
                    continue;
                }
                if (isset($col['prompt']) && ! is_string($col['prompt'])) {
                    continue;
                }
                $colPrompt = isset($col['prompt']) ? $col['prompt'] : '';
                if (mb_strlen($colPrompt) > self::COLUMN_PROMPT_MAX_CHARS) {
                    continue;
                }
                if ($format === $jsonPathFormat) {
                    if (! isset($col['json_path']) || ! is_string($col['json_path'])) {
                        continue;
                    }
                    $jsonPath = trim($col['json_path']);
                    if ($jsonPath === '' || mb_strlen($jsonPath) > self::COLUMN_JSON_PATH_MAX_CHARS) {
                        continue;
                    }
                }
                // Copilot iter 16: defensively strip / reject
                // keys whose type does not match what
                // StoreWorkflowRequest / FromProposalRequest expect.
                // enum_values present but non-array → reject the
                // column. json_path present + non-string OR present
                // when format != json_path → strip it from the
                // normalised payload so downstream `nullable + string`
                // validation accepts the column. Without this, a
                // proposal could pass here and 422 on save with a
                // type-mismatch error.
                if (array_key_exists('enum_values', $col)) {
                    if ($col['enum_values'] !== null && ! is_array($col['enum_values'])) {
                        continue;
                    }
                    if (is_array($col['enum_values'])) {
                        if (count($col['enum_values']) > self::ENUM_VALUES_MAX) {
                            continue;
                        }
                        $bad = false;
                        foreach ($col['enum_values'] as $val) {
                            if (! is_string($val) || mb_strlen($val) > self::ENUM_VALUE_MAX_CHARS) {
                                $bad = true;
                                break;
                            }
                        }
                        if ($bad) {
                            continue;
                        }
                    }
                }
                if (array_key_exists('json_path', $col) && $format !== $jsonPathFormat) {
                    // json_path on a non-json-path column is
                    // meaningless; strip rather than reject so the
                    // proposal isn't lost over a stray key.
                    unset($col['json_path']);
                }
                // Copilot iter 17: build a whitelisted column shape.
                // Appending the raw LLM-provided $col would leak any
                // unexpected keys (LLM hallucinations, prompt
                // injection) into the cached payload + API response
                // and potentially into the workflows.columns_config
                // column when /from-proposal saves the proposal.
                $whitelisted = [
                    'name' => $name,
                    'format' => $format,
                ];
                if (isset($col['prompt']) && is_string($col['prompt']) && $col['prompt'] !== '') {
                    $whitelisted['prompt'] = $col['prompt'];
                }
                if (isset($col['enum_values']) && is_array($col['enum_values'])) {
                    $whitelisted['enum_values'] = array_values(array_filter(
                        $col['enum_values'],
                        fn ($v) => is_string($v),
                    ));
                }
                if ($format === $jsonPathFormat && isset($col['json_path']) && is_string($col['json_path'])) {
                    $whitelisted['json_path'] = trim($col['json_path']);
                }
                $normalised[] = $whitelisted;
            }
            if ($normalised === []) {
                return null;
            }
            $columnsConfig = $normalised;
        } else {
            $columnsConfig = null;
        }

        // Same prompt_md length cap as the FormRequest so a chatty
        // LLM cannot return a 50k-char prompt that 422s on save.
        if (mb_strlen($promptMd) > self::PROMPT_MD_MAX_CHARS) {
            return null;
        }

        return [
            'title' => mb_substr($title, 0, self::TITLE_MAX_CHARS),
            'type' => $type,
            'prompt_md' => $promptMd,
            'columns_config' => $columnsConfig,
            'practice' => $practice,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * @return array{proposals: list<array<string, mixed>>, meta: array{tenant_id: string, documents_analysed: int, cache_hit: bool, reason: string}}
     */
    private function refusal(string $tenant, string $reason, int $analysed = 0): array
    {
        return [
            'proposals' => [],
            'meta' => [
                'tenant_id' => $tenant,
                'documents_analysed' => $analysed,
                'cache_hit' => false,
                'reason' => $reason,
            ],
        ];
    }
}
