<?php

declare(strict_types=1);

namespace App\Services\Kb\Pipeline;

/**
 * Per-project knob controlling which enrichers run during ingest.
 *
 *  - NONE: zero LLM enrichment (cheapest; no auto-tagging, no summary).
 *  - BASIC: language detection + AutoTagger only.
 *  - FULL: BASIC + per-doc summary, entity extraction, topic classification.
 *
 * Operational cost varies by provider, model and document size; per-document
 * spend is observable in the admin AI insights dashboard once concrete
 * enrichers land. Numeric estimates are intentionally omitted from in-code
 * documentation because they drift quickly with provider pricing changes.
 *
 * v3.0 ships the enum + EnricherInterface stub; concrete enrichers land in v3.1.
 */
enum EnrichmentLevel: string
{
    case NONE = 'none';
    case BASIC = 'basic';
    case FULL = 'full';
}
