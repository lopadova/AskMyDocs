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
     * Title char cap. Mirrored in the system prompt and enforced in
     * {@see validateProposal()}. Copilot iter 1 flagged the drift
     * between "max 80 chars" in the prompt vs `mb_substr(..., 200)`
     * in validation — both surfaces now reference this constant.
     */
    private const TITLE_MAX_CHARS = 80;

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

        $cacheKey = sprintf('workflow_suggester:%s:%d', $tenant, $limit);

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
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
        $oversample = self::SAMPLE_SIZE * 3;

        $rows = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('status', 'indexed')
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
        $title = (string) ($proposal['title'] ?? '');
        $type = (string) ($proposal['type'] ?? '');
        $promptMd = (string) ($proposal['prompt_md'] ?? '');
        $practice = (string) ($proposal['practice'] ?? WorkflowPractice::Generic->value);
        $reasoning = (string) ($proposal['reasoning'] ?? '');
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
            // Copilot iter 2: enforce per-column `format` against the
            // FormatType registry so `/suggest` proposals validate
            // against the same schema as StoreWorkflowRequest /
            // FromProposalRequest. Without this, a proposal could
            // look selectable in the FE catalogue then 422 on save.
            // Columns that miss `name` OR `format` OR carry an
            // unknown `format` are dropped from the normalised list.
            $formats = FormatType::values();
            $jsonPathFormat = FormatType::JSON_PATH->value;
            $normalised = [];
            foreach ($columnsConfig as $col) {
                if (! is_array($col)) {
                    continue;
                }
                $name = (string) ($col['name'] ?? '');
                $format = (string) ($col['format'] ?? '');
                if ($name === '' || $format === '' || ! in_array($format, $formats, true)) {
                    continue;
                }
                // Copilot iter 4: `format=json_path` MUST carry a
                // non-empty `json_path`. The downstream
                // FromProposalRequest enforces this with
                // `required_if` and would otherwise 422 a proposal
                // that looked selectable here.
                if ($format === $jsonPathFormat) {
                    $jsonPath = trim((string) ($col['json_path'] ?? ''));
                    if ($jsonPath === '') {
                        continue;
                    }
                }
                $normalised[] = $col;
            }
            if ($normalised === []) {
                return null;
            }
            $columnsConfig = $normalised;
        } else {
            $columnsConfig = null;
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
