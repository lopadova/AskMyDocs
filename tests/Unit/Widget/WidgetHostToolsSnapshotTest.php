<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Services\Widget\WidgetSnapshotValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * F1.3 — Lo snapshot accetta il ramo opzionale host_tools (contratto HTP,
 * spec §3.4-C). Il validator: applica il cap (<= 64) via assertWithinCaps,
 * scarta le voci malformate (name regex, execution === "host"), sanitizza i
 * testi (description/returns) ma NON tocca lo schema parameters.
 */
final class WidgetHostToolsSnapshotTest extends TestCase
{
    private WidgetSnapshotValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new WidgetSnapshotValidator;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hostTools
     * @return array<string, mixed>
     */
    private function snapshotWithHostTools(array $hostTools): array
    {
        return [
            'page' => ['url' => 'https://allowed.test', 'title' => 'Test'],
            'host_tools' => $hostTools,
        ];
    }

    public function test_a_valid_host_tool_is_preserved(): void
    {
        $snapshot = $this->snapshotWithHostTools([[
            'name' => 'articoli__searchArticoli',
            'description' => 'Cerca articoli per nome.',
            'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            'returns' => 'ui-data-table',
            'execution' => 'host',
        ]]);

        $clean = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertCount(1, $clean['host_tools']);
        $this->assertSame('articoli__searchArticoli', $clean['host_tools'][0]['name']);
        // parameters NON è alterato: è una definizione di tool, non testo utente.
        $this->assertSame(
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            $clean['host_tools'][0]['parameters'],
        );
    }

    public function test_host_tool_with_invalid_name_is_discarded(): void
    {
        // Il regex function-name è ^[a-zA-Z0-9_-]+$: spazi/punti non ammessi.
        $snapshot = $this->snapshotWithHostTools([
            ['name' => 'articoli searchArticoli', 'execution' => 'host'],
            ['name' => 'articoli.searchArticoli', 'execution' => 'host'],
            ['name' => 'nodi__searchNodi', 'execution' => 'host'],
        ]);

        $clean = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertCount(1, $clean['host_tools']);
        $this->assertSame('nodi__searchNodi', $clean['host_tools'][0]['name']);
    }

    public function test_host_tool_with_non_host_execution_is_discarded(): void
    {
        $snapshot = $this->snapshotWithHostTools([
            ['name' => 'articoli__a', 'execution' => 'be'],
            ['name' => 'articoli__b'],               // execution mancante
            ['name' => 'articoli__c', 'execution' => 'host'],
        ]);

        $clean = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertCount(1, $clean['host_tools']);
        $this->assertSame('articoli__c', $clean['host_tools'][0]['name']);
    }

    public function test_host_tool_description_is_sanitized(): void
    {
        $snapshot = $this->snapshotWithHostTools([[
            'name' => 'articoli__searchArticoli',
            'description' => 'Cerca <b>articoli</b> ```inject``` ora',
            'execution' => 'host',
        ]]);

        $clean = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertStringNotContainsString('<b>', $clean['host_tools'][0]['description']);
        $this->assertStringNotContainsString('```', $clean['host_tools'][0]['description']);
    }

    public function test_non_array_entries_are_discarded(): void
    {
        $snapshot = $this->snapshotWithHostTools([
            'not-an-array',
            ['name' => 'articoli__ok', 'execution' => 'host'],
        ]);

        $clean = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertCount(1, $clean['host_tools']);
        $this->assertSame('articoli__ok', $clean['host_tools'][0]['name']);
    }

    public function test_host_tools_over_cap_throws(): void
    {
        $hostTools = [];
        for ($i = 0; $i < 65; $i++) {
            $hostTools[] = ['name' => 'articoli__t'.$i, 'execution' => 'host'];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->validator->assertWithinCaps($this->snapshotWithHostTools($hostTools));
    }

    public function test_host_tools_at_cap_passes(): void
    {
        $hostTools = [];
        for ($i = 0; $i < 64; $i++) {
            $hostTools[] = ['name' => 'articoli__t'.$i, 'execution' => 'host'];
        }

        $this->validator->assertWithinCaps($this->snapshotWithHostTools($hostTools));
        $this->assertTrue(true); // nessuna eccezione = cap rispettato
    }

    public function test_snapshot_without_host_tools_is_untouched(): void
    {
        $snapshot = ['page' => ['url' => 'https://allowed.test', 'title' => 'Test']];

        $clean = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertArrayNotHasKey('host_tools', $clean);
    }
}
