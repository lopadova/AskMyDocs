<?php

namespace App\Ai\Middleware;

/**
 * DEPRECATED — context retrieval is now handled directly by KbChatController.
 *
 * The RAG pipeline flow is:
 *   1. KbChatController receives the request
 *   2. KbSearchService generates query embedding + pgvector search
 *   3. Controller builds system prompt via the Blade template
 *   4. AiManager::chat() sends the prompt to the configured provider
 *   5. ChatLogManager persists the interaction (if enabled)
 *
 * This file is kept as a reference. Remove it once you've confirmed
 * no other agent or pipeline references this middleware.
 */
