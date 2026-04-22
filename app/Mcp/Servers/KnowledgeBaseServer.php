<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\KbDocumentBySlugTool;
use App\Mcp\Tools\KbDocumentsByTypeTool;
use App\Mcp\Tools\KbGraphNeighborsTool;
use App\Mcp\Tools\KbGraphSubgraphTool;
use App\Mcp\Tools\KbPromotionSuggestTool;
use App\Mcp\Tools\KbReadChunkTool;
use App\Mcp\Tools\KbReadDocumentTool;
use App\Mcp\Tools\KbRecentChangesTool;
use App\Mcp\Tools\KbSearchTool;
use App\Mcp\Tools\KbSearchByProjectTool;
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
    ];
}
