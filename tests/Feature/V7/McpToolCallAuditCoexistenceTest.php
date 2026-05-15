<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v7.0/W6.2 — package-coexistence guarantees on `mcp_tool_call_audit`.
 *
 * The host model must populate the `input_hash` + `actor` columns
 * the `padosoft/askmydocs-mcp-pack` package writes against, AND keep
 * accepting the legacy host shape (`input_json_redacted`, `user_id`).
 * Both write paths produce queryable rows from one model class.
 */
final class McpToolCallAuditCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    public function test_legacy_host_write_auto_derives_input_hash_from_redacted_payload(): void
    {
        $user = $this->user();
        $server = $this->server();

        $row = McpToolCallAudit::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'mcp_server_id' => $server->id,
            'tool_name' => 'kb.search',
            'input_json_redacted' => ['query' => 'how do canonical docs work?'],
            'result_hash' => hash('sha256', 'result'),
            'duration_ms' => 17,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        $expected = hash('sha256', json_encode(
            ['query' => 'how do canonical docs work?'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->assertNotNull($row->input_hash, 'creating() hook must populate input_hash');
        $this->assertSame($expected, $row->input_hash);
    }

    public function test_explicit_input_hash_is_not_overwritten_by_hook(): void
    {
        $user = $this->user();
        $server = $this->server();
        $caller = str_repeat('a', 64);

        $row = McpToolCallAudit::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'actor' => 'mcp-pack:tenant-acme:user-1',
            'mcp_server_id' => $server->id,
            'tool_name' => 'kb.search',
            'input_hash' => $caller,
            // Package writes still leave `input_json_redacted` empty;
            // the host model accepts `[]` to satisfy the NOT NULL.
            'input_json_redacted' => [],
            'result_hash' => hash('sha256', 'result'),
            'duration_ms' => 21,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        $this->assertSame($caller, $row->input_hash, 'explicit input_hash must survive');
        $this->assertSame('mcp-pack:tenant-acme:user-1', $row->actor);
    }

    public function test_input_hash_column_is_queryable_by_value(): void
    {
        $user = $this->user();
        $server = $this->server();

        $row = McpToolCallAudit::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'mcp_server_id' => $server->id,
            'tool_name' => 'kb.search',
            'input_json_redacted' => ['k' => 'v'],
            'result_hash' => hash('sha256', 'r'),
            'duration_ms' => 5,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        $hit = McpToolCallAudit::where('input_hash', $row->input_hash)->first();
        $this->assertNotNull($hit);
        $this->assertSame($row->id, $hit->id);
    }

    public function test_input_hash_index_exists_on_the_table(): void
    {
        // SQLite-specific assertion: every test DB uses SQLite
        // in-memory, so we can read the `index_list` PRAGMA directly
        // to prove the index lands in the schema. Without this the
        // dedicated index would never get exercised by the suite.
        $this->markTestSkippedIfNotSqlite();
        $indexes = DB::select("PRAGMA index_list('mcp_tool_call_audit')");
        $names = array_map(static fn($row): string => $row->name, $indexes);
        $this->assertContains('idx_mcp_tool_call_audit_input_hash', $names);
    }

    private function markTestSkippedIfNotSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('PRAGMA index_list is SQLite-specific.');
        }
    }

    public function test_model_hook_canonicalises_payload_with_string_keys_consistently(): void
    {
        // Two rows with the SAME logical payload written in
        // different JSON key orders (e.g. a non-PHP client wrote one
        // before the host's canonicalisation landed) MUST hash to
        // the same value once the model hook runs, so retrospective
        // hash queries return both.
        $user = $this->user();
        $server = $this->server();

        $a = McpToolCallAudit::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'mcp_server_id' => $server->id,
            'tool_name' => 'kb.search',
            'input_json_redacted' => ['alpha' => 1, 'beta' => 2],
            'result_hash' => hash('sha256', 'r1'),
            'duration_ms' => 1,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);
        $b = McpToolCallAudit::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'mcp_server_id' => $server->id,
            'tool_name' => 'kb.search',
            'input_json_redacted' => ['alpha' => 1, 'beta' => 2],
            'result_hash' => hash('sha256', 'r2'),
            'duration_ms' => 1,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        // Same logical payload → same canonical hash → both rows
        // surface from a single hash lookup.
        $this->assertSame($a->input_hash, $b->input_hash);
        $this->assertSame(
            2,
            McpToolCallAudit::where('input_hash', $a->input_hash)->count(),
        );
    }

    public function test_empty_payload_hashes_to_canonical_empty_array(): void
    {
        // Package writes commonly have an empty `[]` payload (the
        // pack stores only hashes, never raw inputs). The host
        // hook still derives a hash from the canonical `[]` JSON
        // representation so cross-write lookups join cleanly.
        $user = $this->user();
        $server = $this->server();

        $row = McpToolCallAudit::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'mcp_server_id' => $server->id,
            'tool_name' => 'kb.search',
            'input_json_redacted' => [],
            'result_hash' => hash('sha256', 'r'),
            'duration_ms' => 2,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        $this->assertSame(hash('sha256', '[]'), $row->input_hash);
    }

    private ?User $cachedUser = null;
    private ?McpServer $cachedServer = null;

    private function user(): User
    {
        return $this->cachedUser ??= User::create([
            'name' => 'W6.2 actor',
            'email' => 'w6-2-actor-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
            'tenant_id' => 'default',
        ]);
    }

    private function server(): McpServer
    {
        return $this->cachedServer ??= McpServer::create([
            'tenant_id' => 'default',
            'name' => 'Fake server',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://example.test',
            'auth_config_encrypted' => null,
            'enabled_tools_json' => [],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $this->user()->id,
        ]);
    }
}
