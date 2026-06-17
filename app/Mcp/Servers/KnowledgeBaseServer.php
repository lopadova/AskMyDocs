<?php

namespace App\Mcp\Servers;

use App\Mcp\Methods\ListCollectionResources;
use App\Mcp\Methods\ReadCollectionResource;
use App\Mcp\Tools\KbApplySuggestionTool;
use App\Mcp\Tools\KbDocumentBySlugTool;
use App\Mcp\Tools\KbDetectDecisionDebtTool;
use App\Mcp\Tools\KbDigestPreviewTool;
use App\Mcp\Tools\KbEngagementSummaryTool;
use App\Mcp\Tools\KbUserBadgesTool;
use App\Mcp\Tools\KbDocumentsByTypeTool;
use App\Mcp\Tools\KbGraphNeighborsTool;
use App\Mcp\Tools\KbGraphSubgraphTool;
use App\Mcp\Tools\KbListDanglingWikilinksTool;
use App\Mcp\Tools\KbProposeCanonicalEditTool;
use App\Mcp\Tools\KbPromotionSuggestTool;
use App\Mcp\Tools\KbReadChunkTool;
use App\Mcp\Tools\KbBuildWikiIndexTool;
use App\Mcp\Tools\KbReadDocumentTool;
use App\Mcp\Tools\KbRebuildWikiLinksTool;
use App\Mcp\Tools\KbWikiHubTool;
use App\Mcp\Tools\KbWikiLintTool;
use App\Mcp\Tools\KbWikiMaintainTool;
use App\Mcp\Tools\KbWikiNavigateTool;
use App\Mcp\Tools\KbWikiPromoteTool;
use App\Mcp\Tools\KbWikiReviewTool;
use App\Mcp\Tools\KbRecentChangesTool;
use App\Mcp\Tools\KbSearchTool;
use App\Mcp\Tools\KbSearchByProjectTool;
use App\Mcp\Tools\KbSetEvidenceTierTool;
use App\Mcp\Tools\KbSuggestSupersessionChainTool;
use App\Mcp\Tools\KbSynthesizeConceptsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('enterprise-kb')]
#[Version('2.0.0')]
#[Description('MCP server for the enterprise canonical knowledge base. Read-only retrieval + graph navigation tools, plus a write-nothing promotion-suggest tool that surfaces candidate artifacts for human review.')]
class KnowledgeBaseServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        // Phase 1 — base retrieval
        KbSearchTool::class,
        KbReadDocumentTool::class,
        KbReadChunkTool::class,
        KbRecentChangesTool::class,
        KbSearchByProjectTool::class,
        // Phase 5 — canonical graph + promotion suggest
        KbGraphNeighborsTool::class,
        KbGraphSubgraphTool::class,
        KbDocumentBySlugTool::class,
        KbDocumentsByTypeTool::class,
        KbPromotionSuggestTool::class,
        // Phase 7 — propose-only MCP tools
        KbListDanglingWikilinksTool::class,
        KbDetectDecisionDebtTool::class,
        KbSuggestSupersessionChainTool::class,
        KbProposeCanonicalEditTool::class,
        // v8.11/P1b — evidence-tier write surface (AutoSci #67)
        KbSetEvidenceTierTool::class,
        // v8.11/P2 — auto-wiki graph canonicalization write surface (AutoSci edges)
        KbRebuildWikiLinksTool::class,
        // v8.11/P3 — concept-page synthesis write surface (AutoSci concept pages)
        KbSynthesizeConceptsTool::class,
        // v8.11/P4 — Auto-Wiki indices: build + read the navigation map
        KbBuildWikiIndexTool::class,
        KbWikiHubTool::class,
        // v8.11/P5 — Auto-Wiki lint / wiki health
        KbWikiLintTool::class,
        // v8.11/P6 — agentic multi-hop graph navigation (primary agentic surface)
        KbWikiNavigateTool::class,
        // v8.11/P7 — cross-model review / novelty gate
        KbWikiReviewTool::class,
        // v8.11/P8 — apply engine (change/delete suggestions)
        KbApplySuggestionTool::class,
        // v8.11/P9 — scheduled wiki maintenance (on-demand trigger)
        KbWikiMaintainTool::class,
        // v8.11/P10 — Wiki Explorer promote/discard write surface.
        KbWikiPromoteTool::class,
        // v8.15/W1 — engagement analytics read surface.
        KbEngagementSummaryTool::class,
        // v8.15/W2 — digest preview read surface.
        KbDigestPreviewTool::class,
        // v8.15/W5 — per-contributor gamification badges read surface.
        KbUserBadgesTool::class,
    ];

    protected function boot(): void
    {
        $this->addMethod('resources/list', ListCollectionResources::class);
        $this->addMethod('resources/read', ReadCollectionResource::class);
    }
}
