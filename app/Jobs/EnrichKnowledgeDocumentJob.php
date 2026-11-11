<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Scopes\AccessScopeScope;
use App\Services\Kb\Knowledge\KnowledgeEnrichment;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class EnrichKnowledgeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 540;
    public array $backoff = [30, 120, 300, 600];

    public function __construct(public readonly int $documentId, public readonly string $tenantId)
    {
        $this->onConnection(config('knowledge.connection', 'knowledge'));
        $this->onQueue(config('knowledge.queue', 'knowledge'));
    }

    public function handle(TenantContext $tenants, KnowledgeEnrichment $enrichment): void
    {
        $previous = $tenants->current();
        try {
            $tenants->set($this->tenantId);
            $doc = KnowledgeDocument::query()->withoutGlobalScope(AccessScopeScope::class)->forTenant($this->tenantId)->where('status', 'active')->find($this->documentId);
            if (! $doc) return;
            $run = $enrichment->process($doc);
            if ($run?->status === 'pending' && $enrichment->enabled($doc)) self::dispatch($doc->id, $this->tenantId);
        } finally { $tenants->set($previous); }
    }
}
