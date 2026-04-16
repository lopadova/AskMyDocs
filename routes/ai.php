<?php

use App\Mcp\Servers\KnowledgeBaseServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| AI / MCP Routes
|--------------------------------------------------------------------------
|
| Per ambiente interno semplice:
|   - puoi usare Sanctum
|
| Per esposizione enterprise più robusta verso client remoti:
|   - valuta Passport / OAuth 2.1
|
*/

// Variante semplice:
Mcp::web('/mcp/kb', KnowledgeBaseServer::class)
    ->middleware(['auth:sanctum', 'throttle:api']);

// Variante enterprise (attivare se scegli Passport):
// Mcp::oauthRoutes();
// Mcp::web('/mcp/kb', KnowledgeBaseServer::class)
//     ->middleware(['auth:api', 'throttle:api']);
