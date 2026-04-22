<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Servers\KnowledgeBaseServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Smoke test for the MCP server registration. The `laravel/mcp` package
 * is optional (composer suggest only) — operators who expose the MCP
 * server install it themselves. We therefore cannot instantiate the tools
 * under test, but we CAN assert the server advertises the expected roster
 * via reflection. This catches accidental removals, typos, and wrong class
 * references at PHPUnit time.
 */
class KnowledgeBaseServerRegistrationTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function registeredTools(): array
    {
        $reflection = new ReflectionClass(KnowledgeBaseServer::class);
        $property = $reflection->getProperty('tools');
        $property->setAccessible(true);
        // The property has a default value — read it WITHOUT instantiating
        // the server (which would require Laravel\Mcp\Server).
        return $property->getDefaultValue();
    }

    public function test_server_registers_exactly_ten_tools(): void
    {
        $this->assertCount(10, $this->registeredTools());
    }

    public function test_server_registers_the_five_base_retrieval_tools(): void
    {
        $tools = $this->registeredTools();
        $this->assertContains(\App\Mcp\Tools\KbSearchTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbReadDocumentTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbReadChunkTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbRecentChangesTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbSearchByProjectTool::class, $tools);
    }

    public function test_server_registers_the_five_phase_5_canonical_tools(): void
    {
        $tools = $this->registeredTools();
        $this->assertContains(\App\Mcp\Tools\KbGraphNeighborsTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbGraphSubgraphTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbDocumentBySlugTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbDocumentsByTypeTool::class, $tools);
        $this->assertContains(\App\Mcp\Tools\KbPromotionSuggestTool::class, $tools);
    }

    public function test_every_registered_tool_class_source_file_exists(): void
    {
        // The class_exists check below uses autoload. Because
        // laravel/mcp is a `suggest` dep, class resolution requires the
        // package to be installed OR the source file to be loadable via
        // PSR-4. We assert at the SOURCE FILE level (PSR-4 path) so the
        // test survives environments without laravel/mcp installed.
        foreach ($this->registeredTools() as $toolClass) {
            $relativePath = str_replace(
                ['App\\', '\\'],
                ['app/', '/'],
                $toolClass,
            ) . '.php';
            $absolutePath = __DIR__ . '/../../../' . $relativePath;
            $this->assertFileExists(
                $absolutePath,
                "Registered MCP tool {$toolClass} has no source file at {$relativePath}"
            );
        }
    }

    public function test_server_attributes_advertise_the_expected_name_and_version(): void
    {
        $reflection = new ReflectionClass(KnowledgeBaseServer::class);

        // Read attributes by class string to avoid triggering autoload of
        // the Name/Version/Description attribute classes when laravel/mcp
        // isn't installed in the environment.
        $allAttributes = $reflection->getAttributes();
        $attributeNames = array_map(fn ($attr) => $attr->getName(), $allAttributes);

        $this->assertContains('Laravel\Mcp\Server\Attributes\Name', $attributeNames);
        $this->assertContains('Laravel\Mcp\Server\Attributes\Version', $attributeNames);
        $this->assertContains('Laravel\Mcp\Server\Attributes\Description', $attributeNames);

        // Extract the Name argument without instantiating the attribute.
        foreach ($allAttributes as $attr) {
            if ($attr->getName() !== 'Laravel\Mcp\Server\Attributes\Name') {
                continue;
            }
            $this->assertSame('enterprise-kb', $attr->getArguments()[0]);
        }
        foreach ($allAttributes as $attr) {
            if ($attr->getName() !== 'Laravel\Mcp\Server\Attributes\Version') {
                continue;
            }
            $this->assertSame('2.0.0', $attr->getArguments()[0]);
        }
    }
}
