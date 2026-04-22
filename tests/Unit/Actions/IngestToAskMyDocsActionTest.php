<?php

namespace Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Structural tests for `.github/actions/ingest-to-askmydocs/action.yml`
 * v2 — the composite GitHub Action that syncs markdown changes to
 * AskMyDocs' /api/kb/ingest + /api/kb/documents endpoints.
 *
 * v2 adds canonical-folder awareness (observability-only log step). We
 * assert both the NEW behaviour and the R5 hygiene that pre-existed
 * (large-file handling via jq --rawfile, AMR diff-filter for renames,
 * pattern lock-step between full-sync and diff modes).
 */
class IngestToAskMyDocsActionTest extends TestCase
{
    private const ACTION_PATH = __DIR__ . '/../../../.github/actions/ingest-to-askmydocs/action.yml';

    private string $content;

    /** @var array<string, mixed> */
    private array $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->content = (string) file_get_contents(self::ACTION_PATH);
        $this->action = Yaml::parse($this->content);
    }

    public function test_action_advertises_v2_in_the_name(): void
    {
        $this->assertSame('Ingest docs to AskMyDocs (v2)', $this->action['name']);
    }

    public function test_action_description_mentions_canonical_folder_awareness(): void
    {
        $this->assertStringContainsString('Canonical-aware', $this->action['description']);
        $this->assertStringContainsString('decisions/', $this->action['description']);
        $this->assertStringContainsString('rejected/', $this->action['description']);
    }

    public function test_v2_adds_a_categorization_step(): void
    {
        $stepNames = array_map(fn ($s) => $s['name'] ?? '', $this->action['runs']['steps']);
        $this->assertContains(
            'Categorize canonical content (observability only)',
            $stepNames,
            'v2 must include the Categorize step'
        );
    }

    public function test_categorizer_recognizes_all_8_folder_based_canonical_patterns(): void
    {
        foreach (['decisions/', 'modules/', 'runbooks/', 'standards/', 'incidents/', 'integrations/', 'domain-concepts/', 'rejected/'] as $folder) {
            $this->assertStringContainsString(
                $folder,
                $this->content,
                "Categorizer must recognize folder-based pattern: {$folder}"
            );
        }
    }

    public function test_categorizer_recognizes_the_project_index_filename_convention(): void
    {
        // 9th canonical type — lives at path convention '.' (KB root) so no
        // folder pattern. Detected by filename instead.
        $this->assertStringContainsString(
            'project-index.md)',
            $this->content,
            'Categorizer must match exact filename project-index.md'
        );
        $this->assertStringContainsString(
            '*-index.md)',
            $this->content,
            'Categorizer must match *-index.md (e.g. ecommerce-index.md) for multi-project KBs'
        );
    }

    public function test_categorizer_emits_all_9_canonical_type_strings(): void
    {
        // The case statement must emit the exact enum-value strings used by
        // CanonicalType / config('kb.promotion.path_conventions'). A drift
        // here (e.g. labelling "decisions/" as "adr" instead of "decision")
        // would break the operator's mental model vs the server log.
        foreach ([
            'decision',
            'module-kb',
            'runbook',
            'standard',
            'incident',
            'integration',
            'domain-concept',
            'rejected-approach',
            'project-index',
        ] as $type) {
            $this->assertStringContainsString(
                'echo "' . $type . '"',
                $this->content,
                "Categorizer output must emit canonical type string: {$type}"
            );
        }
    }

    // ---- R5 hygiene invariants preserved from v1 ----

    public function test_r5_large_file_handling_via_jq_rawfile(): void
    {
        // `jq --arg content "$(cat "$file")"` would hit ARG_MAX for large
        // documents. Must use --rawfile instead.
        $this->assertStringContainsString('--rawfile content', $this->content);
        $this->assertStringNotContainsString('--arg content "$(cat', $this->content);
    }

    public function test_r5_diff_filter_includes_renames(): void
    {
        // AMR = Added / Modified / Renamed. Omitting R makes renames look
        // like pure deletes + re-ingests, silently dropping the old path.
        $this->assertStringContainsString('--diff-filter=AMR', $this->content);
    }

    public function test_r5_full_sync_and_diff_patterns_are_locked_in_step(): void
    {
        // Full-sync matches both *.md AND *.markdown when PATTERN is the
        // default "*.md". Diff mode uses a regex that covers both too.
        // If these drift, a repo with *.markdown files sees inconsistent
        // behaviour between full sync and push diff.
        $this->assertStringContainsString('-name "*.md" -o -name "*.markdown"', $this->content);
        $this->assertStringContainsString("grep -Ei '\\.(md|markdown)\$'", $this->content);
    }

    public function test_delete_endpoint_honors_force_flag(): void
    {
        $this->assertStringContainsString('force_delete:', $this->content);
        $this->assertStringContainsString('"force": true', $this->content);
    }

    public function test_all_required_inputs_present(): void
    {
        $required = ['server_url', 'api_token', 'project_key'];
        foreach ($required as $input) {
            $this->assertArrayHasKey($input, $this->action['inputs'], "Missing input: {$input}");
            $this->assertTrue(
                $this->action['inputs'][$input]['required'] ?? false,
                "Input {$input} must be required"
            );
        }
    }
}
