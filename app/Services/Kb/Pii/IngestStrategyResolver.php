<?php

declare(strict_types=1);

namespace App\Services\Kb\Pii;

use InvalidArgumentException;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;

/**
 * v8.23 (Ciclo 4) — turns a strategy NAME (`mask` | `tokenise`) into a
 * concrete {@see RedactionStrategy} instance for the KB ingestion paths.
 *
 * Shared by the connector boundary ({@see \App\Connectors\HostIngestionBridge})
 * and the inline path ({@see \App\Services\Kb\DocumentIngestor}) so the
 * mask-vs-tokenise mapping lives in ONE place. `tokenise` is built through the
 * package factory (so the host TenantResolver + per-tenant salt wire in, R30);
 * `mask` is the one-way default.
 *
 * Throws on an unknown name (R14) — the caller passes a value sourced from the
 * `KB_INGEST_PII_STRATEGY` env knob (or a validated policy row), so a typo is a
 * genuine misconfiguration that must surface loudly rather than silently
 * shipping unredacted text.
 */
final class IngestStrategyResolver
{
    public function __construct(
        private readonly RedactionStrategyFactory $factory,
    ) {}

    public function forName(string $name): RedactionStrategy
    {
        return match ($name) {
            'mask' => app(MaskStrategy::class),
            'tokenise' => $this->factory->make('tokenise'),
            default => throw new InvalidArgumentException(
                "Unknown KB ingest PII strategy '{$name}'. Accepted: mask, tokenise.",
            ),
        };
    }
}
