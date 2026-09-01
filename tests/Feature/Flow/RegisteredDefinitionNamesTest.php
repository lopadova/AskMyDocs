<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\CanonicalIndexFlow;
use App\Flow\Definitions\DeleteDocumentFlow;
use App\Flow\Definitions\IngestDocumentFlow;
use App\Flow\Definitions\IngestFolderFlow;
use App\Flow\Definitions\PromotionFlow;
use App\Flow\Definitions\PruneChatLogsFlow;
use App\Flow\Definitions\PruneDeletedFlow;
use App\Flow\Definitions\PruneEmbeddingCacheFlow;
use App\Flow\Definitions\RebuildGraphFlow;
use App\Mcp\Tools\FlowRunStatusTool;
use Padosoft\LaravelFlow\FlowEngine;
use Tests\TestCase;

/**
 * Guards the flow names the platform advertises to callers.
 *
 * Cloud review on PR #466 caught FlowRunStatusTool telling MCP consumers to
 * filter on 'kb.ingest-document' — a name no definition has ever had. Nothing
 * failed, because a schema description is prose: it is read by an agent
 * deciding what to send, and a wrong value there is a dead end the caller has
 * no way to distinguish from an empty result.
 *
 * The fix builds the examples from the definition constants, so the compiler
 * catches a rename. This test closes the other half — that those constants are
 * actually REGISTERED. A constant is only as good as the `register()` call that
 * puts it in the engine, and removing a definition from
 * FlowServiceProvider::registerDefinitions() would otherwise leave the tool
 * advertising a flow that no longer resolves.
 */
final class RegisteredDefinitionNamesTest extends TestCase
{
    /**
     * The nine definitions the host registers from code. Listed by constant so
     * a rename cannot leave this test asserting a stale string.
     *
     * @return list<string>
     */
    private function expectedNames(): array
    {
        return [
            IngestDocumentFlow::NAME,
            CanonicalIndexFlow::NAME,
            PromotionFlow::NAME,
            DeleteDocumentFlow::NAME,
            PruneDeletedFlow::NAME,
            PruneEmbeddingCacheFlow::NAME,
            PruneChatLogsFlow::NAME,
            RebuildGraphFlow::NAME,
            IngestFolderFlow::NAME,
        ];
    }

    public function test_every_host_definition_is_registered_on_the_engine(): void
    {
        $registered = array_keys(app(FlowEngine::class)->definitions());

        // Without this the test passes vacuously if registration ever moves:
        // an empty engine and a correct one would both satisfy the subset
        // assertion below.
        $this->assertNotEmpty($registered, 'The engine has no definitions at all — registration did not run.');

        foreach ($this->expectedNames() as $name) {
            $this->assertContains($name, $registered, sprintf(
                'Flow [%s] is declared by its definition class but never registered.',
                $name,
            ));
        }
    }

    /**
     * The reverse direction. A definition registered but absent from the list
     * above means this test — and anything else enumerating the flows, the
     * doc-site table included — has gone stale.
     */
    public function test_the_engine_registers_nothing_the_host_does_not_declare(): void
    {
        $registered = array_keys(app(FlowEngine::class)->definitions());

        $this->assertSame(
            [],
            array_values(array_diff($registered, $this->expectedNames())),
            'The engine registers a flow this test does not know about. Add it here and to the doc-site table.',
        );
    }

    /**
     * The finding itself: every flow name the MCP tool names in its schema has
     * to resolve. Read out of the rendered description rather than trusted,
     * because the description is what the caller actually sees.
     */
    public function test_every_flow_name_the_mcp_tool_advertises_resolves(): void
    {
        $description = $this->definitionNameDescription();
        $registered = array_keys(app(FlowEngine::class)->definitions());

        preg_match_all("/'(kb\.[a-z0-9-]+)'/", $description, $matches);
        $advertised = $matches[1];

        $this->assertNotEmpty(
            $advertised,
            'The definition_name description names no flow at all, so this test proves nothing. '
            .'Either it stopped giving an example, or the quoting changed and the pattern needs updating.',
        );

        foreach ($advertised as $name) {
            $this->assertContains($name, $registered, sprintf(
                'The MCP tool tells callers to filter on [%s], which is not a registered flow.',
                $name,
            ));
        }
    }

    /**
     * Read the description off the RENDERED tool definition rather than out of
     * the builder. `toArray()` is the wire format an MCP client actually
     * receives, so this asserts against what the caller sees instead of an
     * intermediate the framework is free to change.
     */
    private function definitionNameDescription(): string
    {
        $rendered = app(FlowRunStatusTool::class)->toArray();

        $properties = $rendered['inputSchema']['properties'] ?? null;

        $this->assertIsArray($properties, 'The rendered tool exposes no inputSchema properties.');
        $this->assertArrayHasKey('definition_name', $properties, 'The tool no longer exposes a definition_name filter.');

        $description = $properties['definition_name']['description'] ?? null;

        $this->assertIsString($description, 'The definition_name filter carries no description for a caller to read.');

        return $description;
    }
}
