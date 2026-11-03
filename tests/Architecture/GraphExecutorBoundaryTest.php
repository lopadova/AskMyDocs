<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0032 permits only the composed API-tool graph, whose generic Flow rows
 * contain opaque references. Definitions, child fan-out and generic node cache
 * are not adopted: their untenanted tables must remain empty. The executable
 * boundary is verified by ApiToolRuntimeTest's real queued-job regression.
 * Any other handler, graph entry point or Cacheable node still requires an
 * explicit tenancy decision before it can enter the host.
 */
final class GraphExecutorBoundaryTest extends TestCase
{
    /**
     * Entry points that would put rows into the untenanted tables.
     *
     * @var list<string>
     */
    private const GRAPH_ENTRY_POINTS = [
        'runGraph(',
        'dryRunGraph(',
        'dispatchGraph(',
        '#[Cacheable',
        'FlowNodeHandler',
        'LegacyStepNodeAdapter',
        'QueueGraphCoordinator',
        'GraphDefinition',
    ];

    public function test_graph_entry_points_are_confined_to_the_reference_only_api_tool_boundary(): void
    {
        $files = $this->hostPhpFiles();

        // Without this the test passes vacuously the day the path is wrong:
        // an empty scan and a clean codebase are the same green.
        $this->assertGreaterThan(500, count($files), 'The scanner found almost no PHP files under app/ — the path is wrong, not the codebase clean.');

        $offenders = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            foreach (self::GRAPH_ENTRY_POINTS as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = substr($file, strlen(dirname(__DIR__, 2).'/app/')).' -> '.$needle;
                }
            }
        }

        sort($offenders);
        $this->assertSame([
            'ApiTools/OperationNode.php -> FlowNodeHandler',
            'ApiTools/ToolGraphCompiler.php -> GraphDefinition',
            'ApiTools/ToolRuntime.php -> QueueGraphCoordinator',
        ], $offenders, implode("\n", [
            'A graph entry point escaped the ADR 0032 API-tool boundary.',
            'flow_definitions, flow_node_children and flow_node_cache remain',
            'unadopted; decide tenancy and extend the executable boundary tests',
            'before adding another graph handler, definition or cache.',
            ...$offenders,
        ]));
    }

    /**
     * @return list<string>
     */
    private function hostPhpFiles(): array
    {
        $root = dirname(__DIR__, 2).'/app';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
