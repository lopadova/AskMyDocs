<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use App\Jobs\AutoWikiCompilerJob;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Log;

final class PostPersistKnowledge
{
    public function dispatch(KnowledgeDocument $document): void
    {
        if (data_get($document->metadata, 'generated_fixture') === true || data_get($document->metadata, 'autowiki_concept')) return;
        try {
            if (app(KnowledgeSpecificationService::class)->effective($document->tenant_id, $document->project_key) !== null) {
                app(KnowledgeEnrichment::class)->schedule($document);
            } elseif (config('kb.autowiki.enabled', true)) {
                AutoWikiCompilerJob::dispatch((int) $document->id, $document->tenant_id)->afterCommit();
            }
        } catch (\Throwable $e) {
            // A paid enrichment failure cannot roll back an already committed import.
            Log::warning('Post-persistence knowledge dispatch failed', ['document_id' => $document->id, 'tenant_id' => $document->tenant_id, 'exception' => $e::class]);
        }
    }
}
