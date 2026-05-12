<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Chunking;

use App\Services\Kb\Chunkers\JiraIssueChunker;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v4.5/W6 — JiraIssueChunker behaviour: preamble emission, description
 * H2 split, comment aggregation, frontmatter strip, source-type
 * routing.
 */
final class JiraIssueChunkerTest extends TestCase
{
    #[Test]
    public function supports_only_jira_source_type(): void
    {
        $chunker = new JiraIssueChunker();
        $this->assertTrue($chunker->supports('jira'));
        $this->assertFalse($chunker->supports('confluence'));
        $this->assertFalse($chunker->supports('notion'));
        $this->assertFalse($chunker->supports('markdown'));
    }

    #[Test]
    public function name_is_stable_lower_kebab(): void
    {
        $this->assertSame('jira-issue-chunker', (new JiraIssueChunker())->name());
    }

    #[Test]
    public function preamble_emitted_first_with_full_property_set(): void
    {
        $chunker = new JiraIssueChunker();
        $doc = new ConvertedDocument(
            markdown: "# [PROJ-1] Summary\n\nIssue description.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'proj-1.jira.md',
                'source_type' => 'jira',
                'jira' => [
                    'project_key' => 'PROJ',
                    'project_name' => 'Project',
                    'issue_key' => 'PROJ-1',
                    'issue_type' => 'Bug',
                    'status' => 'In Progress',
                    'priority' => 'P1',
                    'assignee' => 'alice@example.com',
                    'reporter' => 'bob@example.com',
                    'labels' => ['backend', 'urgent'],
                    'components' => ['api'],
                    'fix_versions' => ['v2.5'],
                    'sprint' => 'Sprint 42',
                ],
                '_derived' => [
                    'search_tags' => ['backend', 'urgent', 'api'],
                    'status_active' => true,
                    'recency_bucket' => 'this_week',
                    'owner' => 'alice@example.com',
                ],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $drafts = $chunker->chunk($doc);

        $this->assertNotEmpty($drafts);
        $preamble = $drafts[0];
        $this->assertTrue($preamble->metadata['page_property_panel']);
        $this->assertSame('preamble', $preamble->metadata['segment']);
        $this->assertSame('PROJ-1 > Issue properties', $preamble->headingPath);
        $this->assertStringContainsString('Project: PROJ (Project)', $preamble->text);
        $this->assertStringContainsString('Type: Bug', $preamble->text);
        $this->assertStringContainsString('Status: In Progress', $preamble->text);
        $this->assertStringContainsString('Priority: P1', $preamble->text);
        $this->assertStringContainsString('Assignee: alice@example.com', $preamble->text);
        $this->assertStringContainsString('Reporter: bob@example.com', $preamble->text);
        $this->assertStringContainsString('Sprint: Sprint 42', $preamble->text);
        $this->assertStringContainsString('Labels: backend, urgent', $preamble->text);
        $this->assertStringContainsString('Components: api', $preamble->text);
        $this->assertStringContainsString('Fix versions: v2.5', $preamble->text);
        $this->assertSame('PROJ', $preamble->metadata['jira_project_key']);
        $this->assertSame('PROJ-1', $preamble->metadata['jira_issue_key']);
        $this->assertSame('In Progress', $preamble->metadata['jira_status']);
    }

    #[Test]
    public function preamble_skipped_when_no_structured_fields_present(): void
    {
        $chunker = new JiraIssueChunker();
        $doc = new ConvertedDocument(
            markdown: "# [PROJ-2] Title\n\nBody only.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'x.jira.md',
                'source_type' => 'jira',
                'jira' => [],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $drafts = $chunker->chunk($doc);
        // No preamble — the first draft is the description body.
        $this->assertNotEmpty($drafts);
        $this->assertSame('description', $drafts[0]->metadata['segment'] ?? null);
    }

    #[Test]
    public function short_issue_emits_single_description_chunk_after_preamble(): void
    {
        $chunker = new JiraIssueChunker();
        $doc = new ConvertedDocument(
            markdown: "# [PROJ-3] Short bug\n\nA single paragraph description.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'proj-3.jira.md',
                'source_type' => 'jira',
                'jira' => ['issue_key' => 'PROJ-3', 'status' => 'Open'],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertCount(2, $drafts);
        $this->assertTrue($drafts[0]->metadata['page_property_panel']);
        $this->assertSame('description', $drafts[1]->metadata['segment']);
        $this->assertStringContainsString('A single paragraph description.', $drafts[1]->text);
        $this->assertSame('PROJ-3 > Description', $drafts[1]->headingPath);
    }

    #[Test]
    public function multi_section_description_splits_on_h2_boundaries(): void
    {
        $chunker = new JiraIssueChunker();
        $markdown = <<<MD
# [PROJ-4] Multi-section issue

## Steps to reproduce

1. Step one
2. Step two

## Expected behaviour

Should work without errors.

## Actual behaviour

Crashes with 500.
MD;

        $doc = new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'filename' => 'proj-4.jira.md',
                'source_type' => 'jira',
                'jira' => ['issue_key' => 'PROJ-4'],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $drafts = $chunker->chunk($doc);
        $headings = array_map(static fn (ChunkDraft $d) => $d->headingPath, $drafts);

        $this->assertContains('PROJ-4 > Steps to reproduce', $headings);
        $this->assertContains('PROJ-4 > Expected behaviour', $headings);
        $this->assertContains('PROJ-4 > Actual behaviour', $headings);
    }

    #[Test]
    public function comments_section_aggregates_into_separate_chunk(): void
    {
        $chunker = new JiraIssueChunker();
        $markdown = <<<MD
# [PROJ-5] Comments issue

Description goes here.

## Comments

### Alice — 2026-05-01T10:00:00Z

First comment body.

### Bob — 2026-05-02T11:00:00Z

Second comment body.
MD;

        $doc = new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'filename' => 'proj-5.jira.md',
                'source_type' => 'jira',
                'jira' => ['issue_key' => 'PROJ-5'],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $drafts = $chunker->chunk($doc);

        $commentChunks = array_values(array_filter(
            $drafts,
            static fn (ChunkDraft $d) => ($d->metadata['segment'] ?? null) === 'comments',
        ));
        $descChunks = array_values(array_filter(
            $drafts,
            static fn (ChunkDraft $d) => ($d->metadata['segment'] ?? null) === 'description',
        ));

        $this->assertNotEmpty($commentChunks, 'Expected at least one comments chunk');
        $this->assertNotEmpty($descChunks, 'Expected description chunk separate from comments');

        // Author + date attribution preserved in the comment chunk body.
        $allCommentText = implode("\n", array_map(static fn (ChunkDraft $d) => $d->text, $commentChunks));
        $this->assertStringContainsString('Alice — 2026-05-01T10:00:00Z', $allCommentText);
        $this->assertStringContainsString('First comment body.', $allCommentText);
        $this->assertStringContainsString('Bob — 2026-05-02T11:00:00Z', $allCommentText);
    }

    #[Test]
    public function frontmatter_is_stripped_before_chunking(): void
    {
        $chunker = new JiraIssueChunker();
        $markdown = "---\nsource: jira\nsource_id: PROJ-6\n---\n# [PROJ-6] Title\n\nDescription body.";

        $doc = new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'filename' => 'proj-6.jira.md',
                'source_type' => 'jira',
                'jira' => ['issue_key' => 'PROJ-6'],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $drafts = $chunker->chunk($doc);
        foreach ($drafts as $d) {
            $this->assertStringNotContainsString('source: jira', $d->text);
            $this->assertStringNotContainsString('---', $d->text);
        }
    }

    #[Test]
    public function derived_signals_propagate_to_chunk_metadata(): void
    {
        $chunker = new JiraIssueChunker();
        $doc = new ConvertedDocument(
            markdown: "# [PROJ-7] X\n\nBody.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'proj-7.jira.md',
                'source_type' => 'jira',
                'jira' => ['issue_key' => 'PROJ-7', 'status' => 'Done'],
                '_derived' => [
                    'search_tags' => ['regression'],
                    'status_active' => false,
                    'recency_bucket' => 'this_quarter',
                    'owner' => 'qa@example.com',
                ],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertNotEmpty($drafts);
        $first = $drafts[0];
        $this->assertSame(['regression'], $first->metadata['search_tags']);
        $this->assertFalse($first->metadata['status_active']);
        $this->assertSame('this_quarter', $first->metadata['recency_bucket']);
        $this->assertSame('qa@example.com', $first->metadata['owner']);
    }

    #[Test]
    public function chunk_order_is_deterministic(): void
    {
        $chunker = new JiraIssueChunker();
        $doc = new ConvertedDocument(
            markdown: "# [PROJ-8] X\n\nBody.\n\n## Section A\n\nA.\n\n## Section B\n\nB.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'proj-8.jira.md',
                'source_type' => 'jira',
                'jira' => ['issue_key' => 'PROJ-8', 'status' => 'Open'],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $drafts = $chunker->chunk($doc);
        $orders = array_map(static fn (ChunkDraft $d) => $d->order, $drafts);

        $this->assertSame(range(0, count($drafts) - 1), $orders);
    }

    #[Test]
    public function chunker_idempotent_across_repeated_invocations(): void
    {
        $chunker = new JiraIssueChunker();
        $doc = new ConvertedDocument(
            markdown: "# [PROJ-9] X\n\nBody one.\n\n## Sec\n\nBody two.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'proj-9.jira.md',
                'source_type' => 'jira',
                'jira' => ['issue_key' => 'PROJ-9'],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.jira.issue+json',
        );

        $first = $chunker->chunk($doc);
        $second = $chunker->chunk($doc);

        $this->assertCount(count($first), $second);
        for ($i = 0; $i < count($first); $i++) {
            $this->assertSame($first[$i]->text, $second[$i]->text);
            $this->assertSame($first[$i]->headingPath, $second[$i]->headingPath);
        }
    }
}
