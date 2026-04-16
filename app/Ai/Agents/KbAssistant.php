<?php

namespace App\Ai\Agents;

use Illuminate\Support\Collection;

/**
 * Knowledge Base assistant agent.
 *
 * Encapsulates prompt building and configuration for the RAG pipeline.
 * Stateless — instantiate per request with the desired project scope.
 */
class KbAssistant
{
    public function __construct(
        protected ?string $projectKey = null,
    ) {}

    /**
     * Build the full system prompt with RAG context injected.
     */
    public function buildSystemPrompt(Collection $chunks): string
    {
        return view('prompts.kb_rag', [
            'chunks' => $chunks,
            'projectKey' => $this->projectKey,
        ])->render();
    }

    public function projectKey(): ?string
    {
        return $this->projectKey;
    }
}
