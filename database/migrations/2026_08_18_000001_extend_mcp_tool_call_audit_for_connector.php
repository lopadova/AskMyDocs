<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'source' => static fn (Blueprint $table) => $table->string('source', 40)->default('legacy_mcp')->after('actor'),
            'mcp_connection_id' => static fn (Blueprint $table) => $table->string('mcp_connection_id', 64)->nullable()->after('mcp_server_name'),
            'invocation_id' => static fn (Blueprint $table) => $table->uuid('invocation_id')->nullable()->after('mcp_connection_id'),
            'tool_remote_name' => static fn (Blueprint $table) => $table->string('tool_remote_name', 255)->nullable()->after('tool_name'),
            'tool_local_name' => static fn (Blueprint $table) => $table->string('tool_local_name', 255)->nullable()->after('tool_remote_name'),
            'error_class' => static fn (Blueprint $table) => $table->string('error_class', 255)->nullable()->after('status'),
        ];
        foreach ($columns as $name => $add) {
            if (Schema::hasColumn('mcp_tool_call_audit', $name)) {
                continue;
            }
            Schema::table('mcp_tool_call_audit', $add);
        }

        if (! Schema::hasIndex('mcp_tool_call_audit', 'idx_mcp_tool_call_audit_tenant_source_connection')) {
            Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'source', 'mcp_connection_id'],
                    'idx_mcp_tool_call_audit_tenant_source_connection',
                );
            });
        }
        if (! Schema::hasIndex('mcp_tool_call_audit', 'idx_mcp_tool_call_audit_invocation')) {
            Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
                $table->index('invocation_id', 'idx_mcp_tool_call_audit_invocation');
            });
        }

        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->foreignId('mcp_server_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('mcp_tool_call_audit')->whereNull('mcp_server_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore non-null mcp_server_id while connector audit rows have no legacy server.',
            );
        }

        if (Schema::hasIndex('mcp_tool_call_audit', 'idx_mcp_tool_call_audit_tenant_source_connection')) {
            Schema::table('mcp_tool_call_audit', static fn (Blueprint $table) => $table->dropIndex('idx_mcp_tool_call_audit_tenant_source_connection'));
        }
        if (Schema::hasIndex('mcp_tool_call_audit', 'idx_mcp_tool_call_audit_invocation')) {
            Schema::table('mcp_tool_call_audit', static fn (Blueprint $table) => $table->dropIndex('idx_mcp_tool_call_audit_invocation'));
        }
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->foreignId('mcp_server_id')->nullable(false)->change();
        });

        $columns = array_values(array_filter([
            'source',
            'mcp_connection_id',
            'invocation_id',
            'tool_remote_name',
            'tool_local_name',
            'error_class',
        ], static fn (string $column): bool => Schema::hasColumn('mcp_tool_call_audit', $column)));
        if ($columns !== []) {
            Schema::table('mcp_tool_call_audit', static fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
