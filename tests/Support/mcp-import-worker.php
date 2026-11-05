<?php

// Separate PHP processes exercise the actual import service against a shared,
// disposable SQLite snapshot. No development database or AI provider is used.
require __DIR__.'/../../vendor/autoload.php';
[$script, $directory, $connectionId, $grantId, $worker] = $argv;
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$directory.'/snapshot.sqlite');
putenv('APP_CONFIG_CACHE='.$directory.'/nonexistent-config.php');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/snapshot.sqlite',
    'database.connections.sqlite.busy_timeout' => 10000,
    'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache',
    'cache.stores.file.lock_path' => $directory.'/cache',
    'filesystems.disks.kb-staging' => ['driver' => 'local', 'root' => $directory.'/staging'],
    'filesystems.disks.kb' => ['driver' => 'local', 'root' => $directory.'/kb']]);
Illuminate\Support\Facades\DB::purge();
$connection = App\Models\McpProjectConnection::findOrFail($connectionId);
$grant = App\Models\OAuthAccessToken::findOrFail($grantId);
app(App\Support\TenantContext::class)->set($connection->tenant_id);
Illuminate\Support\Facades\Auth::setUser(App\Models\User::findOrFail($grant->user_id));
$request = Illuminate\Http\Request::create('/mcp/knowledge/'.$connectionId, 'POST');
$request->attributes->set('mcp.connection', $connection);
$request->attributes->set('mcp.grant', $grant);
$app->instance('request', $request);
touch($directory.'/ready-'.$worker);
$deadline = microtime(true) + 10;
while (! is_file($directory.'/go') && microtime(true) < $deadline) usleep(10000);
try {
    $result = app(App\Services\Mcp\McpDocumentImportService::class)->prepare([
        'documents' => [['filename' => 'concurrent.md', 'content' => '# Concurrent import']],
    ]);
    echo json_encode(['import_id' => $result['import_id']], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
