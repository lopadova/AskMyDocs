<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        // Manual registration (instead of getPackageProviders) avoids
        // ProviderRepository's is_writable() check that fails on Windows
        // for paths containing spaces.
        $app->register(\App\Providers\AiServiceProvider::class);
        $app->register(\App\Providers\ChatLogServiceProvider::class);
        $app->register(\App\Providers\AppServiceProvider::class);
        // Sanctum powers the JSON auth endpoints exercised by
        // tests/Feature/Api/Auth/*. Registered under the same manual
        // pattern as the other project providers above.
        $app->register(\Laravel\Sanctum\SanctumServiceProvider::class);
        // Spatie permissions — registered via the same manual pattern because
        // bootstrap/providers.php uses explicit registration (no package
        // discovery). Without this the Role / Permission models throw
        // "class not registered" under auth:sanctum-protected feature tests.
        $app->register(\Spatie\Permission\PermissionServiceProvider::class);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('ai', require __DIR__.'/../config/ai.php');
        $app['config']->set('kb', require __DIR__.'/../config/kb.php');
        // Load the project's filesystems config so `config('filesystems.disks.kb.driver')`
        // resolves during tests (Testbench's default skeleton has no `kb` disk).
        // HealthCheckService::kbDiskOk reads this to decide whether to hit
        // the disk or validate the config shape.
        $app['config']->set('filesystems', require __DIR__.'/../config/filesystems.php');
        $app['config']->set('chat-log', require __DIR__.'/../config/chat-log.php');
        $app['config']->set('sanctum', require __DIR__.'/../config/sanctum.php');
        $app['config']->set('cors', require __DIR__.'/../config/cors.php');
        $app['config']->set('auth', require __DIR__.'/../config/auth.php');
        $app['config']->set('permission', require __DIR__.'/../config/permission.php');
        $app['config']->set('rbac', require __DIR__.'/../config/rbac.php');
        // Phase G4 / H2 — admin config carries both the PDF engine knob
        // and the H2 allowed_commands whitelist that CommandRunnerService
        // reads at every preview/run call. Without this set, tests get a
        // null config array and every command looks unknown (404).
        $app['config']->set('admin', require __DIR__.'/../config/admin.php');
        $app['config']->set('queue.default', 'sync');

        // Make the project's Blade templates (prompts.kb_rag, prompts.promotion_suggest)
        // resolvable from Orchestra Testbench. Without this, any service that
        // renders a view under tests throws "View [...] not found".
        // `realpath()` returns false when the directory is missing (some
        // minimal test environments); fall back to the non-resolved string
        // so we never end up with `view.paths = [false]`.
        $viewPath = __DIR__.'/../resources/views';
        $app['config']->set('view.paths', [realpath($viewPath) ?: $viewPath]);

        $app['config']->set('auth.providers.users.model', \App\Models\User::class);

        // bootstrap/app.php registers these aliases in production but
        // Orchestra Testbench does not execute that file, so `role:` /
        // `permission:` / `role_or_permission:` middleware declarations
        // would throw `Target class [role] does not exist.` without a
        // manual alias here. Keep the list in sync with bootstrap/app.php.
        $router = $app->make(\Illuminate\Routing\Router::class);
        $router->aliasMiddleware('role', \Spatie\Permission\Middleware\RoleMiddleware::class);
        $router->aliasMiddleware('permission', \Spatie\Permission\Middleware\PermissionMiddleware::class);
        $router->aliasMiddleware('role_or_permission', \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
