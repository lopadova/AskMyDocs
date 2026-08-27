<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Artifacts\AgentTableArtifactFactory;
use Tests\TestCase;

final class AgentTableArtifactFactoryTest extends TestCase
{
    public function test_it_builds_a_selectable_table_from_nested_mcp_items(): void
    {
        $artifact = app(AgentTableArtifactFactory::class)->fromToolEvidence([[
            'execution_id' => 44,
            'tool' => 'search-customers',
            'display_name' => 'Search customers',
            'result' => [
                'status' => 'completed',
                'artifact' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'not the table'],
                        ['type' => 'text', 'text' => 'also not the table'],
                    ],
                    'structuredContent' => [
                        'data' => [
                            'items' => [
                                ['id' => 101, 'name' => 'Riccardo Lorini', 'email' => 'one@example.test', 'api_key' => 'secret-one'],
                                ['id' => 102, 'name' => 'Riccardo Lorini', 'email' => 'two@example.test', 'api_key' => 'secret-two'],
                            ],
                        ],
                    ],
                ],
            ],
        ]], true);

        $this->assertNotNull($artifact);
        $this->assertSame('selection', $artifact['interaction_mode']);
        $this->assertSame(44, $artifact['source_execution_id']);
        $this->assertSame(['101', '102'], array_column($artifact['rows'], 'key'));
        $this->assertSame('Riccardo Lorini', data_get($artifact, 'rows.0.label'));
        $this->assertSame('[EMAIL]', data_get($artifact, 'rows.0.values.email'));
        $this->assertSame('[REDACTED]', data_get($artifact, 'rows.0.record.api_key'));
        $this->assertNotContains('type', array_column($artifact['columns'], 'key'));
    }

    public function test_it_uses_view_mode_for_an_explicit_list_and_ignores_single_records(): void
    {
        $factory = app(AgentTableArtifactFactory::class);
        $view = $factory->fromToolEvidence([[
            'execution_id' => 5,
            'tool' => 'search-orders',
            'result' => ['orders' => [['id' => 1], ['id' => 2]]],
        ]], false);

        $this->assertSame('view', $view['interaction_mode']);
        $this->assertNull($factory->fromToolEvidence([[
            'execution_id' => 6,
            'tool' => 'search-orders',
            'result' => ['orders' => [['id' => 1]]],
        ]], true));
    }
}
