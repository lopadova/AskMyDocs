<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbDocAnalysis;
use App\Services\Kb\Analysis\SuggestionApplier;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.11/P8 — PHP surface (R44) of the apply engine: apply one change/delete
 * suggestion (cross_reference or impacted) from a kb_doc_analyses row. Mirrors
 * the HTTP + MCP surfaces; all three delegate to {@see SuggestionApplier}.
 */
final class KbApplySuggestionCommand extends Command
{
    protected $signature = 'kb:apply-suggestion
        {analysis : kb_doc_analyses id}
        {type : suggestion type — cross_reference|impacted}
        {target : target slug from the analysis}
        {--tenant=default : tenant to scope to}
        {--actor=cli : audit actor label}';

    protected $description = 'Apply a change/delete suggestion (add cross-ref or deprecate impacted doc) (P8).';

    public function handle(SuggestionApplier $applier, TenantContext $tenants): int
    {
        $tenants->set((string) $this->option('tenant'));

        $analysis = KbDocAnalysis::query()
            ->forTenant($tenants->current())
            ->find((int) $this->argument('analysis'));
        if ($analysis === null) {
            $this->error('Analysis not found in tenant '.$this->option('tenant').'.');

            return self::FAILURE;
        }

        $result = $applier->apply(
            $analysis,
            (string) $this->argument('type'),
            (string) $this->argument('target'),
            (string) $this->option('actor'),
        );

        if (($result['applied'] ?? false) !== true) {
            $this->warn('Not applied: '.($result['reason'] ?? 'unknown').'.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Applied %s on %s.', $result['action'], $result['target'] ?? '(?)'));

        return self::SUCCESS;
    }
}
