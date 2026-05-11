<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\PipelineRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R23 — boot-time FQCN validation + `supports()` non-overlap mutex
 * for the chunker registry. After v4.5/W5.5 introduced four new
 * source-aware chunkers, the registry resolution surface grew from
 * 5 source-type tokens to 13. The first-match-wins resolution is
 * load-bearing — if two registered chunkers ever both claim the same
 * token, the route silently picks the wrong handler. This test pins
 * the contract.
 */
final class PipelineRegistryChunkerMutexTest extends TestCase
{
    #[Test]
    public function every_registered_chunker_actually_implements_chunker_interface(): void
    {
        /** @var PipelineRegistry $registry */
        $registry = $this->app->make(PipelineRegistry::class);

        foreach ($registry->allChunkers() as $chunker) {
            $this->assertInstanceOf(
                ChunkerInterface::class,
                $chunker,
                sprintf('Chunker %s must implement ChunkerInterface (R23).', $chunker::class),
            );
        }
    }

    #[Test]
    public function no_two_chunkers_claim_the_same_source_type(): void
    {
        /** @var PipelineRegistry $registry */
        $registry = $this->app->make(PipelineRegistry::class);
        $chunkers = $registry->allChunkers();

        $sourceTypeUniverse = [
            // v3 core
            'markdown', 'md', 'text', 'docx', 'pdf',
            // v4.5/W5.5 source-aware
            'notion', 'notion_note',
            'confluence',
            'evernote', 'fabric',
            'drive_gdoc', 'drive_gsheet', 'drive_gslide',
            'onedrive_office',
        ];

        $overlaps = [];
        foreach ($sourceTypeUniverse as $token) {
            $claimers = [];
            foreach ($chunkers as $chunker) {
                if ($chunker->supports($token)) {
                    $claimers[] = $chunker::class;
                }
            }
            if (count($claimers) > 1) {
                $overlaps[$token] = $claimers;
            }
        }

        $this->assertSame(
            [],
            $overlaps,
            'Chunker supports() predicates overlap on these source-type tokens: '
                . json_encode($overlaps, JSON_PRETTY_PRINT),
        );
    }

    #[Test]
    public function every_v45_source_type_resolves_to_a_chunker(): void
    {
        /** @var PipelineRegistry $registry */
        $registry = $this->app->make(PipelineRegistry::class);
        $tokens = [
            'notion', 'notion_note', 'confluence',
            'evernote', 'fabric',
            'drive_gdoc', 'drive_gsheet', 'drive_gslide', 'onedrive_office',
        ];

        foreach ($tokens as $token) {
            $chunker = $registry->resolveChunker($token);
            $this->assertNotNull($chunker, "No chunker resolves source-type token: {$token}");
        }
    }
}
