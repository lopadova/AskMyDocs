<?php

namespace Tests\Feature\Mcp;

use App\Models\McpProjectConnection;
use App\Models\OAuthAccessToken;
use App\Models\OAuthClient;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class McpImportConcurrencyTest extends TestCase
{
    public function test_two_concurrent_processes_reserve_and_stage_exactly_one_import(): void
    {
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('concurrent');
        $user = User::create(['name' => 'Concurrency fixture', 'email' => 'concurrent@example.test', 'password' => 'test-only']);
        $user->assignRole('admin');
        ProjectMembership::create(['user_id' => $user->id, 'tenant_id' => 'concurrent', 'project_key' => 'engineering', 'role' => 'member']);
        $connection = McpProjectConnection::create(['user_id' => $user->id, 'tenant_id' => 'concurrent', 'project_key' => 'engineering']);
        $client = OAuthClient::create(['name' => 'Fixture', 'redirect_uris' => ['https://example.test/callback'], 'scopes' => ['mcp:read', 'mcp:ingest']]);
        $pat = $user->createToken('fixture', ['mcp:read', 'mcp:ingest', OAuthAccessPolicy::TOKEN_MARKER], now()->addHour());
        $grant = OAuthAccessToken::create(['personal_access_token_id' => $pat->accessToken->id, 'client_id' => $client->id,
            'user_id' => $user->id, 'tenant_id' => 'concurrent', 'scopes' => $client->scopes, 'mcp_connection_id' => $connection->id]);
        $directory = sys_get_temp_dir().'/askmd-mcp-concurrency-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($directory.'/snapshot.sqlite'));
        $processes = [];
        try {
            foreach ([1, 2] as $number) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/mcp-import-worker.php', $directory,
                    $connection->id, (string) $grant->id, (string) $number], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) && microtime(true) < $deadline) usleep(10000);
            $this->assertFileExists($directory.'/ready-1');
            $this->assertFileExists($directory.'/ready-2');
            touch($directory.'/go');
            $ids = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $ids[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['import_id'];
            }
            $this->assertSame($ids[0], $ids[1]);
            $snapshot = new \PDO('sqlite:'.$directory.'/snapshot.sqlite');
            foreach (['mcp_document_imports', 'kb_ingest_batches', 'kb_ingest_batch_items'] as $table) {
                $this->assertSame(1, (int) $snapshot->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            }
            $this->assertSame(0, (int) $snapshot->query('SELECT COUNT(*) FROM knowledge_documents')->fetchColumn());
        } finally {
            foreach ($processes as [$process]) if (is_resource($process)) proc_terminate($process);
            File::deleteDirectory($directory);
        }
    }
}
