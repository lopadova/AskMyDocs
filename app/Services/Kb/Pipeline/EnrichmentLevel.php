<?php

declare(strict_types=1);

namespace App\Services\Kb\Pipeline;

/**
 * Per-project knob controlling which enrichers run during ingest.
 *
 *  - NONE: zero LLM enrichment (cheapest; no auto-tagging, no summary).
 *  - BASIC: language detection + AutoTagger only (~$0.0005/doc typical cost).
 *  - FULL: BASIC + per-doc summary, entity extraction, topic classification (~$0.005/doc).
 *
 * v3.0 ships the enum + EnricherInterface stub; concrete enrichers land in v3.1.
 */
enum EnrichmentLevel: string
{
    case NONE = 'none';
    case BASIC = 'basic';
    case FULL = 'full';
}
