<?php

declare(strict_types=1);
namespace App\Providers;

use App\ApiTools\{OperationNode,ToolHttpBudget};
use App\ApiTools\Flow\{RuntimeFlowStore,ToolTenantScope};
use Illuminate\Bus\Dispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Node\NodeRegistry;

final class ApiToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/api-tools.php','api-tools');
        $this->app->singleton(ToolHttpBudget::class);
        $this->app->extend(FlowStore::class,fn ($store) => new RuntimeFlowStore($store));
    }
    public function boot(): void
    {
        $this->app->make(NodeRegistry::class)->register(OperationNode::class);
        $this->app->make(Dispatcher::class)->pipeThrough([ToolTenantScope::class]);
        Http::globalRequestMiddleware(function ($request) {
            app(ToolHttpBudget::class)->beforeRequest();
            return $request;
        });
        \Padosoft\AskMyDocsConnectorApi\Models\ApiToolCallLog::creating(function ($log): void {
            if ($id = app(ToolHttpBudget::class)->activeRunId()) {
                $log->request_params = ['api_tool_run_id'=>$id];
                $log->response_excerpt = ['stored_encrypted'=>true,'api_tool_run_id'=>$id];
                if ($log->error !== null) { $log->error='api_tool_endpoint_failed'; }
            }
        });
        $this->commands([\App\Console\Commands\CompareApiToolRuns::class]);
        $this->routes();
        foreach ([\Padosoft\AskMyDocsConnectorApi\Models\ApiRoute::class,\Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter::class,\Padosoft\AskMyDocsConnectorApi\Models\ApiConnector::class,\Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile::class] as $model) {
            $callback=static function (): void {
                if (config('api-tools.enabled',false)) { \App\ApiTools\Jobs\SweepApiTools::dispatch()->afterCommit(); }
            };
            $model::saved($callback);
            $model::deleted($callback);
        }
        Schedule::job(new \App\ApiTools\Jobs\SweepApiTools)->everyMinute()->withoutOverlapping();
    }
    private function routes(): void
    {
        $controller=\App\Http\Controllers\Api\Admin\ApiToolController::class;
        Route::middleware(config('connector-api.routes.middleware',[]))
            ->prefix('api/admin/api-tools')->group(function () use ($controller): void {
                Route::get('/',[$controller,'index']);
                Route::middleware(\App\Http\Middleware\ApiToolsEnabled::class)->group(function () use ($controller): void {
                    Route::post('/',[$controller,'store']);
                    Route::post('generate',[$controller,'generate']);
                    Route::post('import',[$controller,'import']);
                    Route::post('runs/{runId}/cancel',[$controller,'cancel']);
                    Route::get('{id}',[$controller,'show'])->whereNumber('id');
                    Route::put('{id}',[$controller,'update'])->whereNumber('id');
                    foreach (['test','publish','disable','enable','export'] as $action) {
                        Route::{ $action === 'export' ? 'get':'post' }('{id}/'.$action,[$controller,$action])->whereNumber('id');
                    }
                    Route::post('{id}/validate',[$controller,'validateDefinition'])->whereNumber('id');
                });
            });
    }
}
