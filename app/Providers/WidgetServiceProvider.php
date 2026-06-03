<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\PruneWidgetSessionsCommand;
use App\Console\Commands\WidgetEmitSecretCommand;
use App\Services\Widget\AiTool\SearchKnowledgeBaseTool;
use App\Services\Widget\WidgetAiToolRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * WidgetServiceProvider — registra i servizi del widget KITT nel container.
 *
 * Il registro AiTool (R23) ha bisogno di bootstrapping esplicito perché
 * i tool built-in FQCN devono essere registrati (validati + instanziati)
 * prima che il controller o l'orchestratore possano usarli.
 *
 * I comandi widget (widget:prune-sessions, widget:emit-secret) sono
 * registrati qui perché Testbench non auto-scopre i comandi dal
 * bootstrap/app.php scheduler; senza registrazione manuale i test
 * li generano CommandNotFoundException.
 */
final class WidgetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WidgetAiToolRegistry::class, function ($app) {
            $registry = new WidgetAiToolRegistry;
            $registry->registerBuiltin(SearchKnowledgeBaseTool::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->commands([
            PruneWidgetSessionsCommand::class,
            WidgetEmitSecretCommand::class,
        ]);
    }
}
