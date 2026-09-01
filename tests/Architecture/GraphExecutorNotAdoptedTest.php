<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Guards an assumption the flow schema depends on.
 *
 * laravel-flow v2 ships three tables the host does not tenant:
 * flow_definitions, flow_node_children and flow_node_cache. That is safe only
 * because the host never runs the graph executor — it registers nine linear
 * definitions and calls Flow::execute(), which takes the v1 path. All three
 * tables therefore stay empty.
 *
 * flow_node_cache is content-addressed with no tenant column BY DESIGN, a
 * cross-tenant reuse layer in the same family as embedding_cache.
 * flow_node_children stores child inputs and outputs, which for this host
 * would be tenant payload.
 *
 * So the day someone adopts graph execution, the tenancy question has to be
 * answered before the first row exists — not discovered afterwards, when the
 * rows are already there and untenanted. This test is what makes that
 * unavoidable: it fails on the commit that introduces the call, not on the
 * incident.
 *
 * If you are here because this test went red: that is the prompt, not an
 * obstacle. Decide the tenancy of the tables you are about to populate, then
 * update this test to describe what you decided.
 */
final class GraphExecutorNotAdoptedTest extends TestCase
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
    ];

    public function test_the_host_does_not_invoke_the_graph_executor(): void
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
                    $offenders[] = basename($file).' -> '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'The host now reaches the v2 graph executor.',
            'flow_definitions, flow_node_children and flow_node_cache carry no',
            'tenant_id, which was only safe while they stayed empty. Decide their',
            'tenancy before these rows exist, then update this test.',
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
