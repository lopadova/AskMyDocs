<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunkers;

use App\Services\Kb\Chunking\Support\DerivedMetadataReader;
use App\Services\Kb\Chunking\Support\TokenCounter;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;

/**
 * v4.5/W6 — Source-aware chunker for Jira issues.
 *
 * Strategy:
 *
 *   1. **Preamble chunk** (always emitted first, even for short issues):
 *      synthetic chunk containing the structured properties (project,
 *      status, priority, assignee, labels, sprint, fix_versions,
 *      components). Marked `metadata.page_property_panel = true` so
 *      the reranker can boost it on "what's the status / who's the
 *      assignee" queries — same convention used by
 *      {@see ConfluencePageChunker} and {@see NotionBlockChunker}.
 *
 *   2. **Description chunk(s)**: split on H2 boundaries when the body
 *      has multiple sections, else one chunk for the whole description.
 *      Long sections are subdivided on paragraph boundaries to stay
 *      under `kb.chunking.target_tokens`.
 *
 *   3. **Comments chunk(s)**: each comment renders under
 *      `### <author> — <created>` (emitted by the connector). The
 *      chunker aggregates consecutive comments until the running
 *      token count crosses target. Author + date stays attached so
 *      the heading_path preserves attribution.
 *
 * The chunker is stateless and deterministic; same `ConvertedDocument`
 * yields identical drafts every call.
 */
final class JiraIssueChunker implements ChunkerInterface
{
    private const SUPPORTED_SOURCE_TYPES = ['jira'];
    private const TARGET_TOKENS_DEFAULT = 500;
    private const HARD_CAP_DEFAULT = 1024;
    private const PARAGRAPH_SEP = '/\n{2,}/';

    private TokenCounter $tokens;
    private DerivedMetadataReader $reader;

    public function __construct(?TokenCounter $tokens = null, ?DerivedMetadataReader $reader = null)
    {
        $this->tokens = $tokens ?? new TokenCounter();
        $this->reader = $reader ?? new DerivedMetadataReader();
    }

    public function name(): string
    {
        return 'jira-issue-chunker';
    }

    public function supports(string $sourceType): bool
    {
        return in_array($sourceType, self::SUPPORTED_SOURCE_TYPES, true);
    }

    /**
     * @return list<ChunkDraft>
     */
    public function chunk(ConvertedDocument $doc): array
    {
        $derived = $this->reader->read($doc);
        $sourceMeta = $this->sourceMetadata($doc);
        $filename = $this->resolveFilename($doc->extractionMeta['filename'] ?? null);
        $target = $this->targetTokens();
        $hardCap = $this->hardCapTokens();
        $issueKey = $this->resolveIssueKey($sourceMeta);

        $drafts = [];

        $preamble = $this->buildPreambleChunk($sourceMeta, $derived, $filename, $issueKey);
        if ($preamble !== null) {
            $drafts[] = $preamble;
        }

        $body = $this->stripFrontmatter($doc->markdown);
        [$description, $comments] = $this->splitDescriptionAndComments($body);

        foreach ($this->chunkDescription($description, $issueKey, $target, $hardCap) as $segment) {
            $drafts[] = new ChunkDraft(
                text: $segment['text'],
                order: count($drafts),
                headingPath: $segment['heading_path'],
                metadata: $this->baseChunkMetadata($sourceMeta, $derived, $filename, [
                    'page_property_panel' => false,
                    'page_block_path' => $segment['heading_path'],
                    'segment' => 'description',
                ]),
            );
        }

        foreach ($this->chunkComments($comments, $issueKey, $target) as $segment) {
            $drafts[] = new ChunkDraft(
                text: $segment['text'],
                order: count($drafts),
                headingPath: $segment['heading_path'],
                metadata: $this->baseChunkMetadata($sourceMeta, $derived, $filename, [
                    'page_property_panel' => false,
                    'page_block_path' => $segment['heading_path'],
                    'segment' => 'comments',
                ]),
            );
        }

        return $drafts;
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     * @param  array{search_tags: list<string>, status_active: bool, recency_bucket: string|null, owner: string|null}  $derived
     */
    private function buildPreambleChunk(array $sourceMeta, array $derived, string $filename, string $issueKey): ?ChunkDraft
    {
        $lines = [];
        $project = $this->stringOrNull($sourceMeta['project_key'] ?? null);
        $projectName = $this->stringOrNull($sourceMeta['project_name'] ?? null);
        $issueType = $this->stringOrNull($sourceMeta['issue_type'] ?? null);
        $status = $this->stringOrNull($sourceMeta['status'] ?? null);
        $priority = $this->stringOrNull($sourceMeta['priority'] ?? null);
        $assignee = $this->stringOrNull($sourceMeta['assignee'] ?? null);
        $reporter = $this->stringOrNull($sourceMeta['reporter'] ?? null);
        $sprint = $this->stringOrNull($sourceMeta['sprint'] ?? null);

        if ($project !== null) {
            $label = $projectName !== null ? "{$project} ({$projectName})" : $project;
            $lines[] = "Project: {$label}";
        }
        if ($issueType !== null) {
            $lines[] = "Type: {$issueType}";
        }
        if ($status !== null) {
            $lines[] = "Status: {$status}";
        }
        if ($priority !== null) {
            $lines[] = "Priority: {$priority}";
        }
        if ($assignee !== null) {
            $lines[] = "Assignee: {$assignee}";
        }
        if ($reporter !== null) {
            $lines[] = "Reporter: {$reporter}";
        }
        if ($sprint !== null) {
            $lines[] = "Sprint: {$sprint}";
        }

        $labels = $this->stringList($sourceMeta['labels'] ?? []);
        if ($labels !== []) {
            $lines[] = 'Labels: '.implode(', ', $labels);
        }

        $components = $this->stringList($sourceMeta['components'] ?? []);
        if ($components !== []) {
            $lines[] = 'Components: '.implode(', ', $components);
        }

        $fixVersions = $this->stringList($sourceMeta['fix_versions'] ?? []);
        if ($fixVersions !== []) {
            $lines[] = 'Fix versions: '.implode(', ', $fixVersions);
        }

        if ($lines === []) {
            return null;
        }

        $text = implode("\n", $lines);
        $heading = $issueKey === '' ? 'Issue properties' : "{$issueKey} > Issue properties";

        return new ChunkDraft(
            text: $text,
            order: 0,
            headingPath: $heading,
            metadata: $this->baseChunkMetadata($sourceMeta, $derived, $filename, [
                'page_property_panel' => true,
                'page_block_path' => $heading,
                'segment' => 'preamble',
            ]),
        );
    }

    /**
     * Split the markdown body into the description segment and the
     * optional `## Comments` appendix.
     *
     * @return array{0: string, 1: string}
     */
    private function splitDescriptionAndComments(string $body): array
    {
        // Normalize line endings so the chunker behaves the same on
        // Linux/Windows CI runners and does not miss the optional
        // comments block due newline encoding differences.
        $body = str_replace("\r", '', trim($body));
        if ($body === '') {
            return ['', ''];
        }

        // The connector prepends `# Title\n\n` before the
        // description; strip leading H1 so the first chunk doesn't
        // include the title twice.
        $body = preg_replace('/^#\s+[^\n]+\n+/', '', $body, 1) ?? $body;

        $parts = preg_split('/^##\s*Comments\s*$/m', $body, 2);
        if (count($parts) !== 2) {
            return [trim($body), ''];
        }

        return [trim($parts[0]), trim($parts[1])];
    }

    /**
     * @return list<array{text: string, heading_path: string}>
     */
    private function chunkDescription(string $description, string $issueKey, int $target, int $hardCap): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }

        // Split on H2 boundaries first. If no H2 headings exist, the
        // whole description is one section.
        $segments = $this->splitOnHeading2($description, $issueKey);
        $out = [];
        foreach ($segments as $seg) {
            foreach ($this->finalize($seg['text'], $seg['heading_path'], $target, $hardCap) as $piece) {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * @return list<array{text: string, heading_path: string}>
     */
    private function splitOnHeading2(string $body, string $issueKey): array
    {
        $defaultHeading = $issueKey === '' ? 'Description' : "{$issueKey} > Description";

        $lines = preg_split('/\r?\n/', $body) ?: [];
        $segments = [];
        $buffer = '';
        $currentHeading = $defaultHeading;
        $inFence = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line) === 1) {
                $inFence = ! $inFence;
                $buffer .= $line."\n";
                continue;
            }
            if (! $inFence && preg_match('/^##\s+(.*?)\s*$/', $line, $m) === 1) {
                if (trim($buffer) !== '') {
                    $segments[] = [
                        'text' => trim($buffer),
                        'heading_path' => $currentHeading,
                    ];
                    $buffer = '';
                }
                $section = trim($m[1]);
                $currentHeading = $issueKey === ''
                    ? $section
                    : "{$issueKey} > {$section}";
                continue;
            }
            $buffer .= $line."\n";
        }
        if (trim($buffer) !== '') {
            $segments[] = [
                'text' => trim($buffer),
                'heading_path' => $currentHeading,
            ];
        }

        return $segments;
    }

    /**
     * @return list<array{text: string, heading_path: string}>
     */
    private function finalize(string $body, string $heading, int $target, int $hardCap): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }
        if ($this->tokens->estimate($body) <= $hardCap) {
            return [['text' => $body, 'heading_path' => $heading]];
        }

        $paragraphs = preg_split(self::PARAGRAPH_SEP, $body) ?: [];
        $out = [];
        $buf = '';
        foreach ($paragraphs as $para) {
            $trimmed = trim($para);
            if ($trimmed === '') {
                continue;
            }
            $candidate = $buf === '' ? $trimmed : $buf."\n\n".$trimmed;
            if ($this->tokens->estimate($candidate) <= $target) {
                $buf = $candidate;
                continue;
            }
            if ($buf !== '') {
                $out[] = ['text' => $buf, 'heading_path' => $heading];
            }
            $buf = $trimmed;
        }
        if ($buf !== '') {
            $out[] = ['text' => $buf, 'heading_path' => $heading];
        }

        return $out === [] ? [['text' => $body, 'heading_path' => $heading]] : $out;
    }

    /**
     * Aggregate consecutive comments into target-sized chunks. Each
     * comment opens with an H3 `### author — date` heading produced
     * by the connector; we keep those intact so attribution rides
     * with the chunk text.
     *
     * @return list<array{text: string, heading_path: string}>
     */
    private function chunkComments(string $commentsBody, string $issueKey, int $target): array
    {
        $commentsBody = trim($commentsBody);
        if ($commentsBody === '') {
            return [];
        }

        $heading = $issueKey === '' ? 'Comments' : "{$issueKey} > Comments";

        // Split on `### ` boundaries — each comment is one chunk
        // candidate. We keep the leading `### ` in the segment so the
        // attribution is preserved inside the chunk text.
        $parts = preg_split('/(?=^###\s)/m', $commentsBody) ?: [];
        $cleanParts = [];
        foreach ($parts as $p) {
            $trimmed = trim($p);
            if ($trimmed !== '') {
                $cleanParts[] = $trimmed;
            }
        }
        if ($cleanParts === []) {
            // No `### ` markers — single chunk.
            return [['text' => $commentsBody, 'heading_path' => $heading]];
        }

        $out = [];
        $buf = '';
        foreach ($cleanParts as $part) {
            $candidate = $buf === '' ? $part : $buf."\n\n".$part;
            if ($this->tokens->estimate($candidate) <= $target) {
                $buf = $candidate;
                continue;
            }
            if ($buf !== '') {
                $out[] = ['text' => $buf, 'heading_path' => $heading];
            }
            $buf = $part;
        }
        if ($buf !== '') {
            $out[] = ['text' => $buf, 'heading_path' => $heading];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     * @param  array{search_tags: list<string>, status_active: bool, recency_bucket: string|null, owner: string|null}  $derived
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function baseChunkMetadata(array $sourceMeta, array $derived, string $filename, array $extra): array
    {
        return array_merge([
            'filename' => $filename,
            'strategy' => 'jira-issue-aware',
            'source_type' => 'jira',
            'search_tags' => $derived['search_tags'],
            'status_active' => $derived['status_active'],
            'recency_bucket' => $derived['recency_bucket'],
            'owner' => $derived['owner'],
            'jira_project_key' => $sourceMeta['project_key'] ?? null,
            'jira_issue_key' => $sourceMeta['issue_key'] ?? null,
            'jira_status' => $sourceMeta['status'] ?? null,
            'jira_issue_type' => $sourceMeta['issue_type'] ?? null,
        ], $extra);
    }

    private function stripFrontmatter(string $markdown): string
    {
        $stripped = preg_replace('/\A---\r?\n.*?\r?\n---\r?\n?/s', '', $markdown, 1);

        return $stripped ?? $markdown;
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceMetadata(ConvertedDocument $doc): array
    {
        $meta = $doc->extractionMeta['jira'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     */
    private function resolveIssueKey(array $sourceMeta): string
    {
        $key = $sourceMeta['issue_key'] ?? '';

        return is_string($key) ? $key : '';
    }

    private function resolveFilename(mixed $raw): string
    {
        if (! is_string($raw)) {
            return 'unknown.jira.md';
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? 'unknown.jira.md' : $trimmed;
    }

    private function stringOrNull(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  mixed  $list
     * @return list<string>
     */
    private function stringList($list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $row) {
            if (is_string($row) && $row !== '') {
                $out[] = $row;
            }
        }

        return array_values(array_unique($out));
    }

    private function targetTokens(): int
    {
        return (int) $this->configValue('kb.chunking.target_tokens', self::TARGET_TOKENS_DEFAULT);
    }

    private function hardCapTokens(): int
    {
        return (int) $this->configValue('kb.chunking.hard_cap_tokens', self::HARD_CAP_DEFAULT);
    }

    private function configValue(string $key, int $default): int
    {
        if (! function_exists('config') || ! function_exists('app')) {
            return $default;
        }
        try {
            if (! app()->bound('config')) {
                return $default;
            }
        } catch (\Throwable) {
            return $default;
        }

        return (int) config($key, $default);
    }
}
