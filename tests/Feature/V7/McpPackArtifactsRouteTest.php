<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Padosoft\AskMyDocsMcpPack\AskMyDocsMcpPackServiceProvider;
use Tests\TestCase;

/**
 * R43 — both states of the pack 2.0 artifact download route, observed at
 * runtime. The test bootstrap registers providers by hand and loads neither
 * the pack's provider nor the host's `config/mcp-pack.php`, so each case
 * registers the provider itself with the host block applied first. That proves
 * the provider honours the host override rather than only that the array says
 * `false`, and the first case proves the premise (the pack ships the route ON)
 * so the OFF assertion cannot pass vacuously.
 */
final class McpPackArtifactsRouteTest extends TestCase
{
    private const ROUTE_NAME = 'mcp-pack.v2.artifacts.download';

    private const URL = '/mcp-pack/v2/artifacts/00000000-0000-4000-8000-000000000000/download';

    #[DefineEnvironment('packDefaults')]
    public function test_the_pack_ships_the_download_route_on_by_default(): void
    {
        $this->assertTrue(Route::has(self::ROUTE_NAME), 'If this fails the host override below is no longer proving anything.');
    }

    #[DefineEnvironment('hostArtifactsBlock')]
    public function test_off_state_does_not_mount_the_download_route(): void
    {
        $this->assertFalse(Route::has(self::ROUTE_NAME));
        $this->get(self::URL)->assertNotFound();
    }

    #[DefineEnvironment('enabledArtifactsBlock')]
    public function test_on_state_mounts_the_route_and_refuses_an_unsigned_request(): void
    {
        $this->assertTrue(Route::has(self::ROUTE_NAME));
        $this->get(self::URL)->assertForbidden();
    }

    /** No host override: the package's own defaults. */
    protected function packDefaults($app): void
    {
        $app->register(AskMyDocsMcpPackServiceProvider::class);
    }

    /** The host block exactly as shipped: artifacts OFF unless the env opts in. */
    protected function hostArtifactsBlock($app): void
    {
        $app['config']->set('mcp-pack.artifacts', $this->hostBlock());
        $app->register(AskMyDocsMcpPackServiceProvider::class);
    }

    /** The same block with the flag flipped, to exercise the enabled state. */
    protected function enabledArtifactsBlock($app): void
    {
        $app['config']->set('mcp-pack.artifacts', ['enabled' => true] + $this->hostBlock());
        $app->register(AskMyDocsMcpPackServiceProvider::class);
    }

    /** @return array<string, mixed> */
    private function hostBlock(): array
    {
        $host = require __DIR__.'/../../../config/mcp-pack.php';

        return $host['artifacts'];
    }
}
