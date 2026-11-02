<?php

// Minimal isolated database bootstrap: never loads application .env or providers.
require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = new Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new Illuminate\Config\Repository(['database' => ['default' => 'sqlite', 'connections' => [
    'sqlite' => ['driver' => 'sqlite', 'database' => $argv[1], 'prefix' => '', 'busy_timeout' => 10000],
]]]));
$db = new Illuminate\Database\Capsule\Manager($app);
$db->addConnection($app['config']['database.connections.sqlite'], 'sqlite');
$db->getDatabaseManager()->setDefaultConnection('sqlite');
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
Illuminate\Support\Facades\Facade::setFacadeApplication($app);

file_put_contents($argv[3].'.'.getmypid().'.ready', 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($argv[3]) && microtime(true) < $deadline) usleep(10000);
if (! file_exists($argv[3])) exit(2);
$campaign = App\Models\QaCampaign::query()->forTenant('acme')->findOrFail($argv[2]);
$run = new App\Models\AgentRun(['tenant_id' => 'acme', 'run_id' => (string) Illuminate\Support\Str::uuid(),
    'input_json' => ['qa' => ['round' => 'baseline', 'case_id' => 'DEMO-01', 'turn' => 1]]]);
try {
    (new App\Services\Qa\CampaignLedger)->reserve($campaign, $run, (string) Illuminate\Support\Str::uuid(),
        ['path' => '/api/v1/chat/completions', 'requested_model' => 'test/model', 'request_sha256' => str_repeat('c', 64)], 600000);
    echo 'allowed';
} catch (DomainException $exception) {
    if ($exception->getMessage() !== 'qa_budget_exhausted') throw $exception;
    echo 'blocked';
}
