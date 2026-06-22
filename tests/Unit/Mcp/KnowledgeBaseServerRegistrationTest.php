<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Servers\KnowledgeBaseServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Smoke test for the MCP server registration. The `laravel/mcp` package
 * is optional (composer suggest only) — operators who expose the MCP
 * server install it themselves. We therefore cannot instantiate the tools
 * under test, but we CAN assert the server advertises the expected roster
 * via reflection. This catches accidental removals, typos, and wrong class
 * references at PHPUnit time.
 */
class KnowledgeBaseServerRegistrationTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function registeredTools(): array
    {
        $reflection = new ReflectionClass(KnowledgeBaseServer::class);
        $property = $reflection->getProperty('tools');
        $property->setAccessible(true);
        // The property has a default value — read it WITHOUT instantiating
        // the server (which would require Laravel\Mcp\Server).
        return $property->getDefaultValue();
    }

    public function test_server_registers_exactly_thirty_four_tools(): void
    {
        $this->assertCount(34, $this->registeredTools());
    }

    public function test_server_registers_the_run_report_tool(): void
    {
        // v8.19/W4 — the Agentic Knowledge Reports MCP read surface (R44 third surface).
        $this->assertContains(\App\Mcp\Tools\KbRunReportTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_gamification_insights_tool(): void
    {
        // v8.18/W4 — the AI gamification insights MCP read surface (R44 third surface).
        $this->assertContains(\App\Mcp\Tools\KbGamificationInsightsTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_guardrails_insights_tool(): void
    {
        // v8.19/W2 — the AI Guardrails posture MCP read surface (R44 third surface).
        $this->assertContains(\App\Mcp\Tools\KbGuardrailsInsightsTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_finops_read_tools(): void
    {
        // v8.16/W4 — the AI FinOps MCP read surfaces (R44 third surface):
        // spend summary + top models (usage ledger) + budget status (budgets).
        $tools = $this->registeredTools();

        $this->assertContains(\App\Mcp\Tools\FinOpsSpendSummaryTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\FinOpsTopModelsTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\FinOpsBudgetStatusTool::class, $tools);
    }

    public function test_server_registers_the_user_badges_tool(): void
    {
        // v8.15/W5 — the per-contributor gamification badges MCP read surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbUserBadgesTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_invite_tools(): void
    {
        // Invite system (R44 third surface) — validate (read) + generate (write).
        $this->assertContains(\App\Mcp\Tools\InviteValidateCodeTool::class, $this->registeredTools());
        $this->assertContains(\App\Mcp\Tools\InviteGenerateCodesTool::class, $this->registeredTools());
        $this->assertContains(\App\Mcp\Tools\InviteMetricsTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_engagement_summary_tool(): void
    {
        // v8.15/W1 — the engagement analytics MCP read surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbEngagementSummaryTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_digest_preview_tool(): void
    {
        // v8.15/W2 — the engagement-digest preview MCP read surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbDigestPreviewTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_wiki_promote_tool(): void
    {
        // v8.11/P10 — the Wiki Explorer promote/discard MCP write surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbWikiPromoteTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_wiki_review_tool(): void
    {
        // v8.11/P7 — the cross-model review MCP surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbWikiReviewTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_wiki_maintain_tool(): void
    {
        // v8.11/P9 — the scheduled-maintenance MCP surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbWikiMaintainTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_apply_suggestion_tool(): void
    {
        // v8.11/P8 — the apply-engine MCP surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbApplySuggestionTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_wiki_lint_tool(): void
    {
        // v8.11/P5 — the Auto-Wiki lint MCP surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbWikiLintTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_wiki_navigate_tool(): void
    {
        // v8.11/P6 — the agentic graph-navigation MCP surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbWikiNavigateTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_concept_synthesis_write_tool(): void
    {
        // v8.11/P3 — the concept-page synthesis MCP write surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbSynthesizeConceptsTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_wiki_index_tools(): void
    {
        // v8.11/P4 — the Auto-Wiki index build + hub-read MCP surfaces (R44).
        $this->assertContains(\App\Mcp\Tools\KbBuildWikiIndexTool::class, $this->registeredTools());
        $this->assertContains(\App\Mcp\Tools\KbWikiHubTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_evidence_tier_write_tool(): void
    {
        // v8.11/P1b — the evidence-tier MCP write surface (AutoSci #67, R44).
        $this->assertContains(\App\Mcp\Tools\KbSetEvidenceTierTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_wiki_link_write_tool(): void
    {
        // v8.11/P2 — the auto-wiki graph canonicalization MCP write surface (R44).
        $this->assertContains(\App\Mcp\Tools\KbRebuildWikiLinksTool::class, $this->registeredTools());
    }

    public function test_server_registers_the_five_base_retrieval_tools(): void
    {
        $tools = $this->registeredTools();
        $this->assertContains(\App\Mcp\Tools\KbSearchTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbReadDocumentTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbReadChunkTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbRecentChangesTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbSearchByProjectTool::class, $tools);
    }

    public function test_server_registers_the_five_phase_5_canonical_tools(): void
    {
        $tools = $this->registeredTools();
        $this->assertContains(\App\Mcp\Tools\KbGraphNeighborsTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbGraphSubgraphTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbDocumentBySlugTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbDocumentsByTypeTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbPromotionSuggestTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbListDanglingWikilinksTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbDetectDecisionDebtTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbSuggestSupersessionChainTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbProposeCanonicalEditTool::class, $tools);
    }

    public function test_every_registered_tool_class_source_file_exists(): void
    {
        // The class_exists check below uses autoload. Because
        // laravel/mcp is a `suggest` dep, class resolution requires the
        // package to be installed OR the source file to be loadable via
        // PSR-4. We assert at the SOURCE FILE level (PSR-4 path) so the
        // test survives environments without laravel/mcp installed.
        foreach ($this->registeredTools() as $toolClass) {
            $relativePath = str_replace(
                ['App\\', '\\'],
                ['app/', '/'],
                $toolClass,
            ) . '.php';
            $absolutePath = __DIR__ . '/../../../' . $relativePath;
            $this->assertFileExists(
                $absolutePath,
                "Registered MCP tool {$toolClass} has no source file at {$relativePath}"
            );
        }
    }

    public function test_server_attributes_advertise_the_expected_name_and_version(): void
    {
        $reflection = new ReflectionClass(KnowledgeBaseServer::class);

        // Read attributes by class string to avoid triggering autoload of
        // the Name/Version/Description attribute classes when laravel/mcp
        // isn't installed in the environment.
        $allAttributes = $reflection->getAttributes();
        $attributeNames = array_map(fn ($attr) => $attr->getName(), $allAttributes);

        $this->assertContains('Laravel\Mcp\Server\Attributes\Name', $attributeNames);
        $this->assertContains('Laravel\Mcp\Server\Attributes\Version', $attributeNames);
        $this->assertContains('Laravel\Mcp\Server\Attributes\Description', $attributeNames);

        // Extract the Name argument without instantiating the attribute.
        foreach ($allAttributes as $attr) {
            if ($attr->getName() !== 'Laravel\Mcp\Server\Attributes\Name') {
                continue;
            }
            $this->assertSame('enterprise-kb', $attr->getArguments()[0]);
        }
        foreach ($allAttributes as $attr) {
            if ($attr->getName() !== 'Laravel\Mcp\Server\Attributes\Version') {
                continue;
            }
            $this->assertSame('2.0.0', $attr->getArguments()[0]);
        }
    }
}
