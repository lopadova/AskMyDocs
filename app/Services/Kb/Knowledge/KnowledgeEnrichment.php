<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use App\Jobs\EnrichKnowledgeDocumentJob;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeExtraction;
use App\Models\KnowledgeObservation;
use App\Models\KnowledgeSection;
use App\Services\Kb\AutoWiki\AutoWikiGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class KnowledgeEnrichment
{
    public function __construct(private readonly KnowledgeSpecificationService $specifications, private readonly KnowledgeSource $sources, private readonly KnowledgeExtractor $extractor) {}

    public function enabled(KnowledgeDocument $doc): bool
    {
        return (bool) config('knowledge.enabled', true) && $doc->status === 'active'
            && app(AutoWikiGate::class)->allows($doc->tenant_id, $doc->project_key, (bool) $doc->is_canonical);
    }

    public function schedule(KnowledgeDocument $doc): void
    {
        if ($this->enabled($doc) && $this->specifications->effective($doc->tenant_id, $doc->project_key) !== null) {
            $contract = $this->specifications->effective($doc->tenant_id, $doc->project_key);
            $run = $this->register($doc, $contract, $this->sources->parts($doc));
            if (! in_array($run->status, ['complete', 'partial'], true)) EnrichKnowledgeDocumentJob::dispatch($doc->id, $doc->tenant_id)->afterCommit();
        }
    }

    public function process(KnowledgeDocument $doc): ?KnowledgeExtraction
    {
        if (! $this->enabled($doc)) return null;
        $contract = $this->specifications->effective($doc->tenant_id, $doc->project_key);
        if ($contract === null) return null;
        $parts = $this->sources->parts($doc);
        $run = $this->register($doc, $contract, $parts);
        $version = $run->source_version;
        $lock = Cache::lock('knowledge-extraction:'.$run->idempotency_key, 600);
        if (! $lock->get()) throw new \RuntimeException('Knowledge extraction is already running.');
        try {
            $run->refresh();
            if (in_array($run->status, ['complete', 'partial'], true)) return $run;
            $run->update(['status' => 'running', 'error' => null]);
            foreach (KnowledgeSection::query()->where('extraction_id', $run->id)->whereIn('status', ['pending', 'failed'])->orderBy('ordinal')->limit(max(1, (int) config('knowledge.sections_per_job', 8)))->get() as $section) {
                if (! $this->enabled($doc)) { $run->update(['status' => 'pending']); return $run; }
                try {
                    if ($section->result === null) {
                        $proposal = $this->extractor->propose($section->text, $contract);
                        $section->update(['result' => $proposal, 'usage' => $proposal['usage'] ?? null]);
                    }
                    $judgments = $section->decisions ?? [];
                    foreach ($section->result['contributions'] as $index => $candidate) {
                        if (isset($judgments[$index])) continue;
                        $valid = is_array($candidate) ? $this->extractor->validate($candidate, $section->text, $contract) : null;
                        $judgments[$index] = $valid === null ? ['status' => 'rejected', 'reason' => 'invalid_identity_or_quote'] : $this->extractor->judge($valid, $contract);
                        // Persist every paid judgment before interpreting or proceeding.
                        $section->update(['decisions' => $judgments]);
                    }
                    $abstained = ! empty($section->result['invalid_output']) || collect($judgments)->contains(fn ($j) => ($j['status'] ?? '') === 'abstained');
                    $section->update(['status' => $abstained ? 'abstained' : 'complete', 'error' => null]);
                } catch (\Throwable $e) {
                    $section->update(['status' => 'failed', 'error' => $e::class]);
                    $run->update(['status' => 'failed', 'error' => $e::class]);
                    throw $e;
                }
            }
            $sections = KnowledgeSection::query()->where('extraction_id', $run->id)->get();
            $processed = $sections->whereIn('status', ['complete', 'abstained'])->count();
            $run->update(['processed_sections' => $processed, 'abstained_sections' => $sections->where('status', 'abstained')->count()]);
            if ($processed < $run->total_sections) { $run->update(['status' => 'pending']); return $run; }
            DB::transaction(function () use ($run, $doc, $contract, $sections, $version) {
                $this->specifications->lockScopes($doc->tenant_id, $doc->project_key);
                $current = KnowledgeDocument::query()->forTenant($doc->tenant_id)->where('project_key', $doc->project_key)->where('status', 'active')->lockForUpdate()->find($doc->id);
                $effective = $this->specifications->effective($doc->tenant_id, $doc->project_key);
                if (! $current || ! $this->enabled($current) || ($effective['hash'] ?? null) !== $contract['hash'] || $this->sources->version($current, $this->sources->parts($current)) !== $version) {
                    $run->update(['status' => 'superseded']); return;
                }
                foreach ($sections as $section) {
                    foreach ($section->result['contributions'] ?? [] as $index => $candidate) {
                        $judgment = $section->decisions[$index] ?? [];
                        if (($judgment['status'] ?? null) !== 'accepted') continue;
                        $valid = $this->extractor->validate($candidate, $section->text, $contract);
                        if ($valid === null) continue;
                        if ($valid['source_identity']) $valid['identifier'] = 'source:'.substr(hash('sha256', $doc->source_path), 0, 40);
                        $entity = $this->entity($doc, $valid);
                        $target = isset($valid['target']) ? $this->entity($doc, $valid['target']) : null;
                        $fingerprint = hash('sha256', json_encode([$run->id, $entity->id, $valid['quote'], $valid['property'], $valid['value'], $target?->id], JSON_THROW_ON_ERROR));
                        KnowledgeObservation::query()->firstOrCreate(['fingerprint' => $fingerprint], [
                            'tenant_id' => $doc->tenant_id, 'project_key' => $doc->project_key, 'entity_id' => $entity->id,
                            'extraction_id' => $run->id, 'section_id' => $section->id, 'target_entity_id' => $target?->id,
                            'relation' => $valid['target']['relation'] ?? null, 'rule' => $valid['rule'], 'property' => $valid['property'],
                            'value' => $valid['value'], 'quote' => $valid['quote'], 'quote_start' => $valid['quote_start'], 'support' => $judgment['support'],
                            // No LLM-invented dates; use the original source timestamp.
                            'event_at' => $doc->source_updated_at,
                        ]);
                    }
                }
                $run->update(['status' => $run->abstained_sections > 0 || $run->total_sections === 0 ? 'partial' : 'complete']);
                app(KnowledgeGraphProjection::class)->project($run);
            });
            return $run->fresh();
        } finally {
            $lock->release();
        }
    }

    /** Durable registration precedes queue submission; a transport failure remains visible/reprocessable. */
    private function register(KnowledgeDocument $doc, array $contract, array $parts): KnowledgeExtraction
    {
        $version = $this->sources->version($doc, $parts);
        $extractorVersion = (string) config('knowledge.extractor_version');
        $key = hash('sha256', json_encode([$doc->tenant_id, $doc->project_key, $doc->id, $version, $contract['hash'], $extractorVersion], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($doc, $contract, $parts, $version, $extractorVersion, $key) {
            $run = KnowledgeExtraction::query()->firstOrCreate(['idempotency_key' => $key], [
                'tenant_id' => $doc->tenant_id, 'project_key' => $doc->project_key, 'knowledge_document_id' => $doc->id,
                'source_version' => $version, 'specification_hash' => $contract['hash'], 'extractor_version' => $extractorVersion, 'contract' => $contract,
            ]);
            $run = KnowledgeExtraction::query()->forTenant($doc->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->total_sections === 0 && $run->status === 'pending') {
                $sections = $this->sources->sections($parts);
                foreach ($sections as $section) KnowledgeSection::query()->firstOrCreate(['extraction_id' => $run->id, 'ordinal' => $section['ordinal']], $section);
                $run->update(['total_sections' => count($sections)]);
            }
            return $run;
        });
    }

    private function entity(KnowledgeDocument $doc, array $identity): KnowledgeEntity
    {
        $key = hash('sha256', json_encode([$doc->tenant_id, $doc->project_key, $identity['type'], $identity['namespace'], $identity['identifier']], JSON_THROW_ON_ERROR));
        return KnowledgeEntity::query()->firstOrCreate(['identity_hash' => $key], ['tenant_id' => $doc->tenant_id,
            'project_key' => $doc->project_key, 'type' => $identity['type'], 'namespace' => $identity['namespace'], 'identifier' => $identity['identifier']]);
    }

    public function preview(KnowledgeDocument $doc, array $contract): array
    {
        $parts = $this->sources->parts($doc);
        $sections = $this->sources->sections($parts);
        $results = [];
        // A deliberately bounded paid preview; missing coverage is explicit.
        foreach (array_slice($sections, 0, 3) as $section) {
            $proposed = $this->extractor->propose($section['text'], $contract);
            foreach ($proposed['contributions'] as $candidate) {
                $valid = is_array($candidate) ? $this->extractor->validate($candidate, $section['text'], $contract) : null;
                $results[] = ['section' => $section['ordinal'], 'candidate' => $valid,
                    'judgment' => $valid ? $this->extractor->judge($valid, $contract) : ['status' => 'rejected', 'reason' => 'invalid_identity_or_quote']];
            }
        }
        return ['document_id' => $doc->id, 'contributions' => $results, 'coverage' => ['total_sections' => count($sections), 'processed_sections' => min(3, count($sections)), 'partial' => count($sections) > 3]];
    }
}
