<?php

declare(strict_types=1);

namespace Tests\Feature\Live;

use PHPUnit\Framework\Attributes\Test;
use Tests\Live\Support\IdentifierScrubber;
use Tests\Live\Support\JsonFixtureLoader;
use Tests\TestCase;

/**
 * Smoke tests for the v4.5/W5.5 live-fixture-recording scaffolding.
 *
 * Live tests themselves are gated by env vars and skip in CI; these
 * tests exercise the SUPPORT code (loader, scrubber) so the
 * scaffolding regressions are caught before an operator wastes time
 * with a half-broken recording session.
 */
final class LiveScaffoldingTest extends TestCase
{
    #[Test]
    public function identifier_scrubber_is_deterministic(): void
    {
        $scrubber = new IdentifierScrubber();
        $a = $scrubber->scrubId('abc-123');
        $b = $scrubber->scrubId('abc-123');
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('sha256-', $a);
    }

    #[Test]
    public function identifier_scrubber_walks_known_id_keys(): void
    {
        $scrubber = new IdentifierScrubber();
        $payload = [
            'id' => 'page-real-id',
            'workspace_id' => 'wks-real',
            'title' => 'do not scrub me',
            'children' => [
                ['block_id' => 'block-real-id', 'text' => 'do not scrub me'],
            ],
        ];

        $out = $scrubber->scrubPayload($payload);
        $this->assertNotSame('page-real-id', $out['id']);
        $this->assertStringStartsWith('sha256-', $out['id']);
        $this->assertNotSame('wks-real', $out['workspace_id']);
        $this->assertSame('do not scrub me', $out['title']);
        $this->assertNotSame('block-real-id', $out['children'][0]['block_id']);
        $this->assertSame('do not scrub me', $out['children'][0]['text']);
    }

    #[Test]
    public function fixture_loader_strips_meta_wrapper(): void
    {
        $body = JsonFixtureLoader::load('notion/hand-crafted/blocks-children-list.sample.json');
        $this->assertArrayHasKey('results', $body);
        $this->assertArrayNotHasKey('__source', $body);
        $this->assertArrayNotHasKey('body', $body);
    }

    #[Test]
    public function every_provider_ships_at_least_one_hand_crafted_sample(): void
    {
        $providers = ['notion', 'confluence', 'evernote', 'fabric', 'google_drive', 'onedrive'];
        $repoRoot = dirname(__DIR__, 2);
        foreach ($providers as $slug) {
            $dir = $repoRoot
                . DIRECTORY_SEPARATOR . 'Fixtures'
                . DIRECTORY_SEPARATOR . 'connectors'
                . DIRECTORY_SEPARATOR . $slug
                . DIRECTORY_SEPARATOR . 'hand-crafted';
            $this->assertDirectoryExists($dir, "Missing hand-crafted dir for provider: {$slug}");
            $samples = glob($dir . DIRECTORY_SEPARATOR . '*.sample.json') ?: [];
            $this->assertNotEmpty($samples, "Provider {$slug} must ship at least one *.sample.json baseline.");
        }
    }
}
