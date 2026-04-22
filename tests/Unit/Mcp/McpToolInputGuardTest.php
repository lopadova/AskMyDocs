<?php

namespace Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Input-guard contracts the 5 Phase 5 tools must enforce. We validate
 * them at the source-code level (reflection + regex of the method body)
 * so the tests work even in environments where laravel/mcp is not
 * installed (the handle() method cannot be invoked without the MCP
 * runtime).
 *
 * These assertions cover the Copilot PR #13 review findings:
 *   - limit params must be double-clamped with `max(1, min(..., MAX))`
 *   - edge_types must intersect with the operator allowlist from config
 *   - existing_slugs must be bounded + slug-shape-validated
 *   - subgraph "truncated" must track a real cap-hit, not count equality
 */
class McpToolInputGuardTest extends TestCase
{
    public function test_graph_neighbors_limit_is_clamped_both_ways(): void
    {
        $source = $this->sourceOf(\App\Mcp\Tools\KbGraphNeighborsTool::class);
        $this->assertMatchesRegularExpression(
            '/max\(1\s*,\s*min\(\$rawLimit\s*,\s*self::MAX_LIMIT\)\)/',
            $source,
            'KbGraphNeighborsTool::handle must clamp limit with max(1, min(..., MAX_LIMIT))'
        );
    }

    public function test_documents_by_type_limit_is_clamped_both_ways(): void
    {
        $source = $this->sourceOf(\App\Mcp\Tools\KbDocumentsByTypeTool::class);
        $this->assertMatchesRegularExpression(
            '/max\(1\s*,\s*min\(\$rawLimit\s*,\s*self::MAX_LIMIT\)\)/',
            $source,
            'KbDocumentsByTypeTool::handle must clamp limit with max(1, min(..., MAX_LIMIT))'
        );
    }

    public function test_graph_neighbors_intersects_user_input_with_operator_allowlist(): void
    {
        $source = $this->sourceOf(\App\Mcp\Tools\KbGraphNeighborsTool::class);
        $this->assertStringContainsString(
            'array_intersect',
            $source,
            'KbGraphNeighborsTool must intersect user-supplied edge_types with operator allowlist'
        );
        $this->assertStringContainsString(
            'kb.graph.expansion_edge_types',
            $source,
            'KbGraphNeighborsTool must read the operator allowlist from kb.graph.expansion_edge_types'
        );
    }

    public function test_promotion_suggest_normalizes_existing_slugs_with_a_cap(): void
    {
        $source = $this->sourceOf(\App\Mcp\Tools\KbPromotionSuggestTool::class);
        $this->assertStringContainsString(
            'MAX_EXISTING_SLUGS',
            $source,
            'KbPromotionSuggestTool must cap existing_slugs via a MAX_EXISTING_SLUGS constant'
        );
        $this->assertStringContainsString(
            'SLUG_RE',
            $source,
            'KbPromotionSuggestTool must validate each existing slug against a slug regex'
        );
        $this->assertMatchesRegularExpression(
            '/private\s+const\s+MAX_EXISTING_SLUGS\s*=\s*\d+/',
            $source,
            'MAX_EXISTING_SLUGS must be declared as a const'
        );
    }

    public function test_subgraph_tool_returns_truncated_from_real_cap_hit(): void
    {
        $source = $this->sourceOf(\App\Mcp\Tools\KbGraphSubgraphTool::class);
        // The walkBfs method returns a 3-tuple: [visited, edges, truncated].
        // Truncated must NOT be derived from count() >= max at the end —
        // it must be set explicitly inside the loop when the cap triggers.
        $this->assertStringContainsString(
            '$truncated = true',
            $source,
            'KbGraphSubgraphTool::walkBfs must set $truncated = true when the cap actually triggers'
        );
        $this->assertStringNotContainsString(
            "'truncated' => count(\$visitedNodes) >= \$maxNodes",
            $source,
            'KbGraphSubgraphTool must not derive truncated from a count-equality test (false positive when subgraph size == cap exactly)'
        );
    }

    private function sourceOf(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $filename = $reflection->getFileName();
        $this->assertNotFalse($filename, "Could not locate source file for $class");
        $content = file_get_contents($filename);
        $this->assertNotFalse($content, "Could not read source file $filename");
        return $content;
    }
}
