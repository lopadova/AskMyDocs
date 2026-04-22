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

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('ai', require __DIR__.'/../config/ai.php');
        $app['config']->set('kb', require __DIR__.'/../config/kb.php');
        $app['config']->set('chat-log', require __DIR__.'/../config/chat-log.php');
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
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
