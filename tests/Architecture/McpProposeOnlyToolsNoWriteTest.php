<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

final class McpProposeOnlyToolsNoWriteTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function w72ToolFiles(): array
    {
        return [
            __DIR__ . '/../../app/Mcp/Tools/KbListDanglingWikilinksTool.php',
            __DIR__ . '/../../app/Mcp/Tools/KbDetectDecisionDebtTool.php',
            __DIR__ . '/../../app/Mcp/Tools/KbSuggestSupersessionChainTool.php',
            __DIR__ . '/../../app/Mcp/Tools/KbProposeCanonicalEditTool.php',
        ];
    }

    public function test_w72_propose_only_tools_do_not_call_write_paths(): void
    {
        $forbidden = [
            'Storage::put(',
            'CanonicalWriter::write(',
            'IngestDocumentJob::dispatch(',
        ];

        foreach ($this->w72ToolFiles() as $file) {
            $this->assertFileExists($file);
            $src = file_get_contents($file) ?: '';
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $src,
                    basename($file) . " must not call {$needle}"
                );
            }
        }
    }
}
