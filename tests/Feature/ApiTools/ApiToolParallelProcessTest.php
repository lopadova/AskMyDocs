<?php

declare(strict_types=1);

namespace Tests\Feature\ApiTools;

use App\Agent\AgentExecutionContext;
use App\ApiTools\Models\ApiToolRun;
use App\ApiTools\ToolLifecycle;
use App\ApiTools\ToolRuntime;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Executor\Jobs\NodeJob;
use Tests\TestCase;

/** A rendezvous inside both HTTP calls proves independent workers overlap. */
final class ApiToolParallelProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_real_node_jobs_run_in_separate_processes_before_the_join(): void
    {
        $this->exerciseParallelJobs(false);
    }

    public function test_two_real_https_requests_overlap_in_distinct_node_worker_processes(): void
    {
        if (getenv('API_TOOLS_LIVE_PARALLEL') !== '1') {
            $this->markTestSkipped('Opt in to public HTTPS overlap with API_TOOLS_LIVE_PARALLEL=1.');
        }
        $this->exerciseParallelJobs(true);
    }

    private function exerciseParallelJobs(bool $live): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('The process integration check requires pcntl.'); }
        $directory = sys_get_temp_dir().'/api-tool-process-'.Str::uuid();
        mkdir($directory, 0700, true);
        $database = $directory.'/shared.sqlite';
        $previous = DB::getDefaultConnection();
        $children = [];
        try {
            $this->copyDatabase(DB::connection()->getPdo(), $database);
            config(['database.connections.api_tool_parallel' => array_merge(config('database.connections.sqlite'), ['database' => $database, 'busy_timeout' => 5000]),
                'api-tools.enabled' => true, 'api-tools.connection' => null,
                'laravel-flow.executor.lock_store' => 'file', 'laravel-flow.default_storage' => null,
                'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/locks',
                'connector-api.ssrf.resolve_dns' => $live, 'connector-api.defaults.retry_backoff_ms' => 0, 'connector-api.defaults.retry_times' => 1]);
            DB::setDefaultConnection('api_tool_parallel');
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=5000');
            Cache::forgetDriver('file');
            Queue::fake();
            $tenant = 'parallel-tenant';
            app(\App\Support\TenantContext::class)->set($tenant);
            app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set($tenant);
            $user = User::create(['name' => 'Parallel tester', 'email' => 'parallel@example.test', 'password' => 'test-password']);
            ProjectMembership::create(['tenant_id' => $tenant, 'user_id' => $user->id, 'project_key' => 'sales', 'role' => 'member']);
            $context = new AgentExecutionContext((string) Str::uuid(), $tenant, 'sales', 'chat', 'user', (string) $user->id, 'en', 'UTC');
            $connector = ApiConnector::create(['tenant_id' => $tenant, 'project_key' => 'sales', 'name' => 'Parallel API', 'is_active' => true]);
            $steps = [];
            foreach (['left' => 1, 'right' => 2] as $id => $remoteId) {
                $route = ApiRoute::create(['tenant_id' => $tenant, 'project_key' => 'sales', 'api_connector_id' => $connector->id,
                    'name' => $id, 'slug' => $id, 'url' => $live ? 'https://jsonplaceholder.typicode.com/users/'.$remoteId : 'https://api.example.com/'.$id,
                    'http_method' => 'GET', 'mode' => 'tool', 'status' => 'active', 'cache_ttl_s' => 0, 'timeout_ms' => 15000,
                    'input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object', 'properties' => $live ? ['id' => ['type' => 'integer']] : ['worker' => ['type' => 'string']]]]);
                $steps[] = ['id' => $id, 'operation' => 'api', 'route_id' => $route->id, 'bindings' => []];
            }
            $tool = app(ToolLifecycle::class)->saveDraft(null, ['name' => 'Parallel worker check', 'definition' => [
                'version' => 1, 'input_schema' => ['type' => 'object'], 'steps' => $steps,
                'output' => ['left' => ['source' => 'step', 'step' => 'left', 'path' => $live ? 'id' : 'worker'], 'right' => ['source' => 'step', 'step' => 'right', 'path' => $live ? 'id' : 'worker']],
                'limits' => ['physical_requests' => 2, 'timeout_seconds' => 60],
            ]], $context);
            $tool->update(['status' => 'active', 'publication_intent' => true]);
            $run = app(ToolRuntime::class)->start($tool, [], $context);
            $bus = app(\Illuminate\Contracts\Bus\Dispatcher::class);
            $coordinator = Queue::pushed(CoordinatorJob::class)->first();
            $bus->dispatchNow($coordinator);
            $roots = Queue::pushed(NodeJob::class)->all();
            $this->assertCount(2, $roots);
            $dependencies = array_map(fn ($wire) => $wire->sourceNodeId, array_filter($coordinator->graph->connections, fn ($wire) => $wire->targetNodeId === 'tool_output'));
            $this->assertEqualsCanonicalizing(['left', 'right'], $dependencies);
            $this->assertSame('pending', DB::table('flow_run_nodes')->where('run_id', $run->flow_run_id)->where('node_id', 'tool_output')->value('status'));
            DB::disconnect('api_tool_parallel');
            foreach ($roots as $node) {
                $pid = pcntl_fork();
                if ($pid === -1) { $this->fail('Unable to fork a node worker.'); }
                if ($pid === 0) {
                    try {
                        DB::purge('api_tool_parallel');
                        DB::statement('PRAGMA busy_timeout=5000');
                        if ($live) {
                            // These middleware observe the actual connector transport; no fake is installed.
                            Http::globalRequestMiddleware(function ($request) use ($directory, $node) {
                                $this->enterHttpBarrier($directory, $node->nodeId, $node->runId);
                                file_put_contents($directory.'/'.$node->nodeId.'.send.json', json_encode([
                                    'pid' => getmypid(), 'url' => (string) $request->getUri(), 'start_ns' => hrtime(true),
                                ], JSON_THROW_ON_ERROR));
                                return $request;
                            });
                            Http::globalResponseMiddleware(function ($response) use ($directory, $node) {
                                file_put_contents($directory.'/'.$node->nodeId.'.finish.json', json_encode([
                                    'pid' => getmypid(), 'status' => $response->getStatusCode(), 'finish_ns' => hrtime(true),
                                ], JSON_THROW_ON_ERROR));
                                return $response;
                            });
                        } else {
                            Http::fake(function ($request) use ($directory, $node) {
                                $this->enterHttpBarrier($directory, $node->nodeId, $node->runId);
                                return Http::response(['worker' => $node->nodeId]);
                            });
                        }
                        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($node);
                        $result = DB::table('flow_run_nodes')->where('run_id', $node->runId)->where('node_id', $node->nodeId)->first(['status', 'error_class', 'error_message']);
                        if ($result->status !== 'succeeded') { throw new \RuntimeException(json_encode($result)); }
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($directory.'/'.$node->nodeId.'.error', $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $errors = array_map('file_get_contents', glob($directory.'/*.error'));
                $errors[] = 'files: '.implode(',', array_map('basename', glob($directory.'/*')));
                foreach (glob($directory.'/*.timeout') as $timeout) { $errors[] = file_get_contents($timeout); }
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, implode("\n", $errors));
            }
            $children = [];
            DB::purge('api_tool_parallel');
            $this->assertNotSame(file_get_contents($directory.'/left.entered'), file_get_contents($directory.'/right.entered'));
            $states = DB::table('flow_run_nodes')->where('run_id', $run->flow_run_id)->pluck('status', 'node_id')->all();
            $this->assertSame('succeeded', $states['left']);
            $this->assertSame('succeeded', $states['right']);
            $this->assertSame('pending', $states['tool_output']);
            $bus->dispatchNow($coordinator);
            $join = Queue::pushed(NodeJob::class)->first(fn ($job) => $job->nodeId === 'tool_output');
            $this->assertNotNull($join);
            $bus->dispatchNow($join);
            $bus->dispatchNow($coordinator);
            $finished = ApiToolRun::query()->findOrFail($run->id);
            $this->assertSame('completed', $finished->status);
            $this->assertSame($live ? ['left' => 1, 'right' => 2] : ['left' => 'left', 'right' => 'right'], $finished->outputs);
            $this->assertSame(2, $finished->physical_requests);
            if ($live) {
                $intervals = [];
                foreach (['left', 'right'] as $id) {
                    $start = json_decode(file_get_contents($directory.'/'.$id.'.send.json'), true, flags: JSON_THROW_ON_ERROR);
                    $finish = json_decode(file_get_contents($directory.'/'.$id.'.finish.json'), true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame($start['pid'], $finish['pid']);
                    $this->assertSame(200, $finish['status']);
                    $this->assertGreaterThan($start['start_ns'], $finish['finish_ns']);
                    $intervals[$id] = array_merge($start, $finish);
                }
                $this->assertNotSame($intervals['left']['pid'], $intervals['right']['pid']);
                $latestSend = max(array_column($intervals, 'start_ns'));
                $earliestFinish = min(array_column($intervals, 'finish_ns'));
                $this->assertLessThan($earliestFinish, $latestSend, 'The HTTPS send/completion intervals did not overlap.');
                file_put_contents(sys_get_temp_dir().'/api-tools-live-parallel-proof.json', json_encode([
                    'verified_at' => now()->toIso8601String(), 'intervals' => $intervals,
                    'overlap_ms' => ($earliestFinish - $latestSend) / 1000000,
                    'outputs' => $finished->outputs, 'physical_requests' => $finished->physical_requests,
                    'dependency_join_verified' => true,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        } finally {
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); }
            DB::disconnect('api_tool_parallel');
            DB::setDefaultConnection($previous);
            $this->deleteDirectory($directory);
        }
    }

    private function enterHttpBarrier(string $directory, string $nodeId, string $flowRunId): void
    {
        file_put_contents($directory.'/'.$nodeId.'.entered', (string) getmypid());
        $deadline = microtime(true) + 8;
        while (true) {
            clearstatcache();
            if (is_file($directory.'/left.entered') && is_file($directory.'/right.entered')) { break; }
            if (microtime(true) > $deadline) {
                file_put_contents($directory.'/'.$nodeId.'.timeout', implode(',', glob($directory.'/*.entered')));
                throw new \RuntimeException('The independent node never entered concurrently.');
            }
            usleep(1000);
        }
        $states = DB::table('flow_run_nodes')->where('run_id', $flowRunId)->pluck('status', 'node_id')->all();
        if (($states['tool_output'] ?? null) !== 'pending' || ($states['left'] ?? null) !== 'running' || ($states['right'] ?? null) !== 'running') {
            throw new \RuntimeException('The join advanced before both HTTP dependencies completed.');
        }
    }

    private function copyDatabase(\PDO $source, string $path): void
    {
        $target = new \PDO('sqlite:'.$path);
        $target->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $target->exec('PRAGMA foreign_keys=OFF');
        $objects = $source->query("SELECT name, type, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($objects as $object) {
            if ($object['type'] !== 'table') { continue; }
            $target->exec($object['sql']);
            $name = '"'.str_replace('"', '""', $object['name']).'"';
            foreach ($source->query('SELECT * FROM '.$name)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $columns = implode(',', array_map(fn ($key) => '"'.str_replace('"', '""', $key).'"', array_keys($row)));
                $statement = $target->prepare('INSERT INTO '.$name.' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                $statement->execute(array_values($row));
            }
        }
        foreach ($objects as $object) { if ($object['type'] !== 'table') { $target->exec($object['sql']); } }
    }

    private function deleteDirectory(string $path): void
    {
        foreach (glob($path.'/*') as $file) { is_dir($file) ? $this->deleteDirectory($file) : unlink($file); }
        if (is_dir($path)) { rmdir($path); }
    }
}
