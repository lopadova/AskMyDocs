<?php

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * R9 docs-match-code integrity check for the canonical compilation docs.
 *
 * After Phase 7 ships, three markdown files advertise the canonical
 * subsystem: README.md, CLAUDE.md, and .github/copilot-instructions.md.
 * They share a small set of LOAD-BEARING facts (column names, enum sizes,
 * env var counts, scheduled time slots). This test asserts those facts
 * stay consistent across the three files — so a silent drift between
 * the README and CLAUDE.md doesn't survive grep.
 */
class CanonicalDocsIntegrityTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    private string $readme;
    private string $claude;
    private string $copilot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readme = (string) file_get_contents(self::PROJECT_ROOT . '/README.md');
        $this->claude = (string) file_get_contents(self::PROJECT_ROOT . '/CLAUDE.md');
        $this->copilot = (string) file_get_contents(self::PROJECT_ROOT . '/.github/copilot-instructions.md');
    }

    public function test_all_three_docs_advertise_9_canonical_types(): void
    {
        // README uses "9 types", CLAUDE uses "9 node types" / "9 canonical
        // types", copilot uses "9 values" / "9 types". Match the digit.
        foreach (['README.md' => $this->readme, 'CLAUDE.md' => $this->claude, 'copilot-instructions.md' => $this->copilot] as $name => $body) {
            $this->assertMatchesRegularExpression(
                '/\b9\b/',
                $body,
                "[$name] must mention the number 9 somewhere (9 canonical types)"
            );
        }
    }

    public function test_all_three_docs_advertise_10_edge_types(): void
    {
        foreach (['README.md' => $this->readme, 'CLAUDE.md' => $this->claude, 'copilot-instructions.md' => $this->copilot] as $name => $body) {
            $this->assertMatchesRegularExpression(
                '/10\s+(relations|values|edge\s+types|total\s+tools)/i',
                $body,
                "[$name] must mention 10 edge types or 10 tools somewhere"
            );
        }
    }

    public function test_canonical_column_names_match_reality(): void
    {
        // Column names are load-bearing for any query that quotes them.
        // If we drift here the future reader assumes the wrong schema.
        $columns = ['doc_id', 'slug', 'canonical_type', 'canonical_status', 'is_canonical', 'retrieval_priority', 'source_of_truth', 'frontmatter_json'];
        foreach ($columns as $col) {
            $this->assertStringContainsString($col, $this->claude, "CLAUDE.md must mention column: $col");
            $this->assertStringContainsString($col, $this->copilot, "copilot-instructions.md must mention column: $col");
        }
    }

    public function test_new_tables_named_consistently(): void
    {
        foreach (['kb_nodes', 'kb_edges', 'kb_canonical_audit'] as $table) {
            foreach (['README.md' => $this->readme, 'CLAUDE.md' => $this->claude, 'copilot-instructions.md' => $this->copilot] as $name => $body) {
                $this->assertStringContainsString($table, $body, "[$name] must mention table: $table");
            }
        }
    }

    public function test_scheduler_mentions_kb_rebuild_graph_at_0340(): void
    {
        foreach (['CLAUDE.md' => $this->claude, 'copilot-instructions.md' => $this->copilot] as $name => $body) {
            $this->assertStringContainsString('kb:rebuild-graph', $body, "[$name] must mention kb:rebuild-graph");
            $this->assertStringContainsString('03:40', $body, "[$name] must mention the 03:40 schedule slot");
        }
    }

    public function test_readme_includes_the_canonical_section_heading(): void
    {
        $this->assertStringContainsString(
            '## Canonical Knowledge Compilation',
            $this->readme,
            'README.md must have the "## Canonical Knowledge Compilation" heading'
        );
    }

    public function test_readme_has_the_four_new_badges(): void
    {
        $this->assertStringContainsString('Canonical--KB', $this->readme);
        $this->assertStringContainsString('Knowledge%20Graph', $this->readme);
        $this->assertStringContainsString('Anti--Repetition', $this->readme);
        // MCP badge now says "10 tools" not just "Server"
        $this->assertStringContainsString('MCP-10%20tools', $this->readme);
    }

    public function test_r10_rule_present_in_both_rule_files(): void
    {
        $this->assertStringContainsString('### R10', $this->claude, 'CLAUDE.md must have R10 rule');
        $this->assertStringContainsString('### R10', $this->copilot, 'copilot-instructions.md must have R10 rule');

        // Both must link to the canonical-awareness skill.
        $this->assertStringContainsString('canonical-awareness', $this->claude);
        $this->assertStringContainsString('canonical-awareness', $this->copilot);
    }

    public function test_adrs_referenced_in_rule_files(): void
    {
        $this->assertStringContainsString('ADR 0003', $this->claude, 'CLAUDE.md must reference ADR 0003 for the promotion rule');
        $this->assertStringContainsString('ADR 0003', $this->copilot, 'copilot-instructions.md must reference ADR 0003');
    }

    public function test_promotion_endpoints_match_routes(): void
    {
        $routes = [
            '/api/kb/promotion/suggest',
            '/api/kb/promotion/candidates',
            '/api/kb/promotion/promote',
        ];
        foreach ($routes as $route) {
            $this->assertStringContainsString($route, $this->readme, "README.md must mention route: $route");
            $this->assertStringContainsString($route, $this->claude, "CLAUDE.md must mention route: $route");
        }

        // Verify against routes/api.php — the real source of truth (R9).
        $routesFile = (string) file_get_contents(self::PROJECT_ROOT . '/routes/api.php');
        foreach (['/kb/promotion/suggest', '/kb/promotion/candidates', '/kb/promotion/promote'] as $route) {
            $this->assertStringContainsString($route, $routesFile, "routes/api.php must register: $route");
        }
    }
}
