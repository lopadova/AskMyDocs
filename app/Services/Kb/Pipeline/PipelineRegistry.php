<?php

declare(strict_types=1);

namespace App\Services\Kb\Pipeline;

use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Contracts\EnricherInterface;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Pluggable ingestion pipeline registry (v3.0 / T1.4).
 *
 * Boots every Converter, Chunker, and Enricher listed in `config/kb-pipeline.php`,
 * then resolves them on demand by MIME type (Converter) or source-type token
 * (Chunker). Order in the config is significant — the FIRST implementation
 * that returns `true` from `supports()` wins.
 *
 * Bound as a singleton in {@see \App\Providers\AppServiceProvider::register()}
 * so the boot cost is paid once per request.
 *
 * @see \App\Services\Kb\DocumentIngestor::ingest() for the consuming
 *      polymorphic entry point.
 */
final class PipelineRegistry
{
    /** @var list<ConverterInterface> */
    private array $converters = [];

    /** @var list<ChunkerInterface> */
    private array $chunkers = [];

    /** @var list<EnricherInterface> */
    private array $enrichers = [];

    /**
     * @param  array{converters?: list<class-string>, chunkers?: list<class-string>, enrichers?: list<class-string>, mime_to_source_type?: array<string,string>}  $config
     */
    public function __construct(Container $app, array $config)
    {
        foreach ($config['converters'] ?? [] as $cls) {
            $this->converters[] = $app->make($cls);
        }
        foreach ($config['chunkers'] ?? [] as $cls) {
            $this->chunkers[] = $app->make($cls);
        }
        foreach ($config['enrichers'] ?? [] as $cls) {
            $this->enrichers[] = $app->make($cls);
        }
    }

    /**
     * @throws RuntimeException when no registered converter supports `$mimeType`.
     */
    public function resolveConverter(string $mimeType): ConverterInterface
    {
        foreach ($this->converters as $c) {
            if ($c->supports($mimeType)) {
                return $c;
            }
        }
        throw new RuntimeException("No converter registered for MIME type: {$mimeType}");
    }

    /**
     * @throws RuntimeException when no registered chunker supports `$sourceType`.
     */
    public function resolveChunker(string $sourceType): ChunkerInterface
    {
        foreach ($this->chunkers as $c) {
            if ($c->supports($sourceType)) {
                return $c;
            }
        }
        throw new RuntimeException("No chunker registered for source type: {$sourceType}");
    }

    /** @return list<ConverterInterface> */
    public function allConverters(): array
    {
        return $this->converters;
    }

    /** @return list<ChunkerInterface> */
    public function allChunkers(): array
    {
        return $this->chunkers;
    }

    /** @return list<EnricherInterface> */
    public function allEnrichers(): array
    {
        return $this->enrichers;
    }
}
