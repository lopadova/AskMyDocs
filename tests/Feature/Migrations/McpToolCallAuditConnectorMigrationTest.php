<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class McpToolCallAuditConnectorMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_can_roll_back_and_reapply_without_schema_drift(): void
    {
        $migration = require dirname(__DIR__, 3)
            .'/database/migrations/2026_08_18_000001_extend_mcp_tool_call_audit_for_connector.php';

        $migration->down();

        foreach ($this->connectorColumns() as $column) {
            $this->assertFalse(Schema::hasColumn('mcp_tool_call_audit', $column));
        }
        $this->assertFalse($this->column('mcp_server_id')['nullable']);

        $migration->up();

        foreach ($this->connectorColumns() as $column) {
            $this->assertTrue(Schema::hasColumn('mcp_tool_call_audit', $column));
        }
        $this->assertTrue($this->column('mcp_server_id')['nullable']);
    }

    /** @return list<string> */
    private function connectorColumns(): array
    {
        return [
            'source',
            'mcp_connection_id',
            'invocation_id',
            'tool_remote_name',
            'tool_local_name',
            'error_class',
        ];
    }

    /** @return array<string,mixed> */
    private function column(string $name): array
    {
        $column = collect(Schema::getColumns('mcp_tool_call_audit'))->firstWhere('name', $name);
        $this->assertIsArray($column);

        return $column;
    }
}
