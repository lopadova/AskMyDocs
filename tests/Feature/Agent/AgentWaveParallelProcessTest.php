<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoop;
use App\Agent\Waves\AgentWaveExecutor;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\ProjectMembership;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeChunk;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenant;
use Tests\TestCase;

final class AgentWaveParallelProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_mixed_actions_join_before_replanning_and_dependent_action(): void
    {
        if (! function_exists('pcntl_fork')) $this->markTestSkipped('Requires pcntl.');
        $directory = sys_get_temp_dir().'/agent-wave-'.Str::uuid();
        mkdir($directory, 0700, true);
        $previous = DB::getDefaultConnection();
        try {
            $this->copyDatabase(DB::connection()->getPdo(), $directory.'/db.sqlite');
            config(['database.connections.wave_parallel' => array_merge(config('database.connections.sqlite'), ['database' => $directory.'/db.sqlite', 'busy_timeout' => 5000]),
                'agent.waves.enabled' => true, 'agent.planner.mode' => 'classic', 'kb.investigation.enabled' => true,
                'reasoning.enabled' => true, 'reasoning.parallel_research' => true,
                'connector-api.ssrf.resolve_dns' => false, 'connector-api.defaults.retry_times' => 1]);
            DB::setDefaultConnection('wave_parallel');
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=5000');
            app(TenantContext::class)->set('wave-tenant');
            app(PackageTenant::class)->set('wave-tenant');
            $user = User::create(['name' => 'Wave', 'email' => 'wave-process@example.test', 'password' => 'password']);
            ProjectMembership::create(['tenant_id' => 'wave-tenant', 'user_id' => $user->id, 'project_key' => 'wave-project', 'role' => 'member']);
            $this->actingAs($user);
            \App\Models\KbRetrievalProfile::create(['tenant_id' => 'wave-tenant', 'project_key' => 'wave-project', 'company_context' => 'Order fulfillment']);
            $text = 'The order has shipped.';
            $document = KnowledgeDocument::create(['tenant_id' => 'wave-tenant', 'project_key' => 'wave-project', 'source_type' => 'markdown', 'title' => 'Shipping', 'source_path' => 'shipping.md', 'status' => 'active', 'document_hash' => hash('sha256', $text)]);
            $chunk = KnowledgeChunk::create(['tenant_id' => 'wave-tenant', 'project_key' => 'wave-project', 'knowledge_document_id' => $document->id, 'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text)]);
            $conversation = Conversation::create(['tenant_id' => 'wave-tenant', 'user_id' => $user->id, 'project_key' => 'wave-project']);
            $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'wave-tenant', 'project_key' => 'wave-project',
                'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id,
                'conversation_id' => $conversation->id, 'locale' => 'en', 'timezone' => 'UTC', 'status' => AgentRun::STATUS_RUNNING,
                'input_json' => ['question' => 'Check order and shipment'], 'budget_json' => [], 'counters_json' => [], 'started_at' => now(),
                'result_json' => ['question_understanding' => ['available' => true, 'language' => 'en', 'action' => 'research', 'requires_research' => true,
                    'intent' => 'Check order and shipment', 'kb_queries' => [], 'mentions' => [], 'context_mode' => 'new',
                    'subquestions' => [['question' => 'order', 'topic' => 'order', 'identifiers' => [], 'aspect' => 'status', 'fields' => []],
                        ['question' => 'shipment', 'topic' => 'shipment', 'identifiers' => [], 'aspect' => 'status', 'fields' => []]]]]]);
            $connector = ApiConnector::create(['tenant_id' => 'wave-tenant', 'project_key' => 'wave-project', 'name' => 'ERP', 'is_active' => true]);
            foreach (['get_order', 'get_followup'] as $slug) ApiRoute::create(['tenant_id' => 'wave-tenant', 'project_key' => 'wave-project',
                'api_connector_id' => $connector->id, 'name' => $slug, 'slug' => $slug, 'http_method' => 'GET',
                'url' => 'https://erp.example.com/'.$slug, 'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'detail',
                'tool_definition' => ['name' => $slug, 'description' => $slug, 'input_schema' => ['type' => 'object', 'properties' => []]]]);
            $barrier = static function (string $name) use ($directory): void {
                file_put_contents("$directory/$name.start", json_encode(['pid' => getmypid(), 'time' => microtime(true)]));
                $deadline = microtime(true) + 8;
                do {
                    clearstatcache();
                    if (is_file("$directory/kb.start") && is_file("$directory/api.start")) break;
                    if (microtime(true) > $deadline) throw new \RuntimeException('Workers did not overlap: '.$name);
                    usleep(10000);
                } while (true);
                usleep(100000);
                file_put_contents("$directory/$name.end", (string) microtime(true));
            };
            $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
            $retrieval->shouldReceive('retrieve')->andReturnUsing(function () use ($barrier, $document, $chunk, $text) { $barrier('kb'); return new SearchResult(collect([['document' => $document->toArray(), 'project_key' => 'wave-project', 'chunk_id' => $chunk->id, 'chunk_text' => $text, 'chunk_hash' => $chunk->chunk_hash]]), collect(), collect()); });
            $this->app->instance(ChatRetrievalService::class, $retrieval);
            Http::fake(function ($request) use ($barrier, $directory) {
                if (str_contains((string) $request->url(), 'get_order')) $barrier('api');
                else file_put_contents("$directory/followup.start", (string) microtime(true));
                return Http::response(['id' => 'ORDER-42'], 200);
            });
            $this->app->instance(AgentWaveExecutor::class, new ForkedWaveExecutor($directory));
            $parent = getmypid(); $plans = 0;
            $ai = Mockery::mock(AiManager::class);
            $ai->shouldReceive('chatWithHistory')->andReturnUsing(function ($system, $messages) use (&$plans, $parent, $directory) {
                if (getmypid() !== $parent) throw new \RuntimeException('A child attempted to plan');
                $plans++;
                $action = static fn ($id, $tool, $depends = []) => ['id' => $id, 'tool' => $tool, 'arguments' => [], 'depends_on' => $depends, 'purpose' => 'Read'];
                if ($plans === 1) $payload = ['decision' => 'tools', 'actions' => [
                    ['id' => 'kb', 'tool' => 'search_knowledge_base', 'arguments' => ['query' => 'order'], 'depends_on' => [], 'purpose' => 'Search'],
                    $action('orders', 'get_order'), $action('followup', 'get_followup', ['orders'])]];
                elseif ($plans === 2) {
                    if (! is_file("$directory/kb.end") || ! is_file("$directory/api.end")) throw new \RuntimeException('Planner ran before join');
                    $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
                    if (count($input['completed_actions'] ?? []) !== 2) throw new \RuntimeException('Planner did not receive both completed actions');
                    $payload = ['decision' => 'tools', 'actions' => [$action('followup', 'get_followup', ['orders'])]];
                } else $payload = ['decision' => 'answer', 'actions' => []];
                return new AiResponse('', 'fake', 'fake', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => $payload]]);
            });
            $this->app->instance(AiManager::class, $ai);
            $outcome = app(AgentLoop::class)->run($run, AgentExecutionContext::fromArray($run->toArray()));
            $this->assertSame('answer', $outcome->decision, json_encode([$outcome->stopReason, $outcome->completedActions]));
            $this->assertSame(3, $plans);
            $this->assertCount(1, $outcome->evidence->documents());
            $kb = json_decode(file_get_contents("$directory/kb.start"), true);
            $api = json_decode(file_get_contents("$directory/api.start"), true);
            $this->assertNotSame($kb['pid'], $api['pid']);
            $this->assertLessThan(min((float) file_get_contents("$directory/kb.end"), (float) file_get_contents("$directory/api.end")), max($kb['time'], $api['time']));
            $this->assertGreaterThanOrEqual(max((float) file_get_contents("$directory/kb.end"), (float) file_get_contents("$directory/api.end")), (float) file_get_contents("$directory/followup.start"));
            $this->assertSame(3, $run->fresh()->counters_json['logical_calls']);
            $this->assertSame(2, $run->fresh()->counters_json['physical_calls']);
        } finally {
            DB::disconnect('wave_parallel');
            DB::setDefaultConnection($previous);
            $this->deleteDirectory($directory);
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
    }}

/** Forks actual coordinator closures with the test's registered fakes inherited by the child. */
final class ForkedWaveExecutor extends AgentWaveExecutor
{
    public function __construct(private string $directory) {}

    public function run(array $tasks, bool $forceProcess = false, ?\Closure $diagnostics = null, ?float $timeoutSeconds = null): array
    {
        DB::disconnect('wave_parallel');
        $children = [];
        foreach ($tasks as $key => $task) {
            $path = $this->directory.'/result-'.hash('sha256', (string) $key);
            $pid = pcntl_fork();
            if ($pid === -1) throw new \RuntimeException('fork failed');
            if ($pid === 0) {
                try {
                    DB::purge('wave_parallel');
                    DB::statement('PRAGMA busy_timeout=5000');
                    file_put_contents($path, serialize(['result' => $task()]));
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents($path, serialize(['error' => $e::class.': '.$e->getMessage()]));
                    exit(1);
                }
            }
            $children[$key] = [$pid, $path];
        }
        $results = [];
        foreach ($children as $key => [$pid, $path]) {
            pcntl_waitpid($pid, $status);
            $data = unserialize(file_get_contents($path));
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) throw new \RuntimeException($data['error'] ?? 'Worker failed');
            $results[$key] = $data['result'];
        }
        DB::purge('wave_parallel');
        return $results;
    }
}
