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
                                ['id' => 101, 'display_name' => 'Riccardo Lorini', 'email' => 'one@example.test', 'api_key' => 'secret-one'],
                                ['id' => 102, 'display_name' => 'Riccardo Lorini', 'email' => 'two@example.test', 'api_key' => 'secret-two'],
                            ],
                            'pagination' => ['page' => 1, 'per_page' => 2, 'total' => 108, 'last_page' => 54],
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
        $this->assertSame(108, $artifact['total_rows']);
        $this->assertTrue($artifact['truncated']);
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
        $singleTool = [[
            'execution_id' => 6,
            'tool' => 'search-orders',
            'result' => ['data' => [
                'items' => [[
                    'id' => 1,
                    'number' => 'I016426',
                    'date' => '2025-12-10',
                    'status' => ['id' => 17, 'code' => 'CONF', 'label' => 'CONFERMATO'],
                    'total' => ['amount' => '513.00', 'currency' => 'EUR'],
                ]],
                'pagination' => ['total' => 1],
            ]],
        ]];
        $this->assertNull($factory->fromToolEvidence($singleTool, true));
        $this->assertNull($factory->fromToolEvidence($singleTool, false));
        $singleView = $factory->fromToolEvidence($singleTool, false, true);
        $this->assertSame('view', $singleView['interaction_mode']);
        $this->assertSame('I016426', data_get($singleView, 'rows.0.label'));
        $this->assertSame(
            ['id', 'number', 'date', 'status.label', 'status.code', 'total.amount', 'total.currency'],
            array_column($singleView['columns'], 'key'),
        );
    }

    public function test_it_uses_selected_execution_and_declared_collection_path(): void
    {
        $artifact = app(AgentTableArtifactFactory::class)->fromToolEvidence([
            [
                'execution_id' => 70,
                'tool' => 'old_search',
                'result' => ['items' => [
                    ['id' => 'WRONG-1'],
                    ['id' => 'WRONG-2'],
                ]],
            ],
            [
                'execution_id' => 71,
                'tool' => 'orders_get',
                'presentation' => ['collection_path' => 'data.orders'],
                'result' => [
                    'artifact' => [
                        'structuredContent' => [
                            'data' => [
                                'orders' => [
                                    ['id' => 'ORDER-1', 'line_items' => [['id' => 'LINE-1'], ['id' => 'LINE-2']]],
                                    ['id' => 'ORDER-2', 'line_items' => [['id' => 'LINE-3']]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], false, false, [71]);

        $this->assertNotNull($artifact);
        $this->assertSame(71, $artifact['source_execution_id']);
        $this->assertSame(['ORDER-1', 'ORDER-2'], array_column($artifact['rows'], 'key'));
        $this->assertSame(['id'], array_column($artifact['columns'], 'key'));
    }
}
