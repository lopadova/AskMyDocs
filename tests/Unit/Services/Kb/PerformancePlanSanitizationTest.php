<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb;

use PHPUnit\Framework\TestCase;

final class PerformancePlanSanitizationTest extends TestCase
{
    public function test_plan_output_recursively_omits_expressions_containing_private_bindings(): void
    {
        if (! defined('ASKMYDOCS_PERFORMANCE_LIBRARY_ONLY')) {
            define('ASKMYDOCS_PERFORMANCE_LIBRARY_ONLY', true);
        }
        require_once dirname(__DIR__, 4).'/scripts/performance/cloud-snapshot.php';
        $safe = askmydocsPerformancePlan(['Node Type' => 'Sort', 'Plan Rows' => 24,
            'Sort Key' => ['embedding <=> private-vector'], 'Filter' => "tenant_id = 'private-tenant'",
            'Plans' => [['Node Type' => 'Index Scan', 'Index Name' => 'knowledge_chunks_pkey',
                'Actual Rows' => 10, 'Index Cond' => "project_key = 'private-project'", 'Output' => ['private-content']]]]);
        $this->assertSame(24, $safe['Plan Rows']);
        $this->assertSame(10, $safe['Plans'][0]['Actual Rows']);
        $this->assertSame('knowledge_chunks_pkey', $safe['Plans'][0]['Index Name']);
        $this->assertStringNotContainsString('private-', json_encode($safe));
    }
}
