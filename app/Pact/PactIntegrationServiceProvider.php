<?php

declare(strict_types=1);

namespace App\Pact;

use Illuminate\Support\ServiceProvider;
use Padosoft\PactAgent\AgentChatContract;
use Padosoft\PactAgent\AgentDefinition;
use Padosoft\PactAgent\Contracts\IdentityAuthorization;
use Padosoft\PactAgent\Contracts\InboundAuthenticator;
use Padosoft\PactAgent\Contracts\Runtime;
use Padosoft\PactAgent\PactAgentServiceProvider;

final class PactIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! class_exists(PactAgentServiceProvider::class)) return;
        $this->app->register(PactAgentServiceProvider::class);
        $this->app->bind(InboundAuthenticator::class, PactAuthorization::class);
        $this->app->bind(IdentityAuthorization::class, PactAuthorization::class);
        $this->app->bind(Runtime::class, PactRuntime::class);
        $this->app['config']->set('pact-agent.coordinator.endpoint_resolver', CoordinatorConnection::class);
        $this->app['config']->set('pact-agent.coordinator.credentials', CoordinatorConnection::class);
        $hosts = [];
        if (app()->environment(['local', 'testing']) && config('pact-auth.allow_local_http')) {
            foreach (config('pact-auth.allowed_client_origins', []) as $origin) if (parse_url($origin, PHP_URL_SCHEME) === 'http') $hosts[] = parse_url($origin, PHP_URL_HOST);
        }
        $this->app['config']->set('pact-agent.coordinator.allowed_insecure_hosts', array_values(array_filter($hosts)));
        $origin = rtrim((string) $this->app['config']->get('app.url'), '/');
        $this->app['config']->set('pact-agent.agents.askmydocs', new AgentDefinition('askmydocs', 'AskMyDocs', [
            'endpoint' => $origin.'/api/pact/a2a', 'contract' => AgentChatContract::descriptor(), 'registry_path' => AgentChatContract::registry(),
        ]));
        $this->app['config']->set('pact-agent.http_aliases', [
            '/api/pact/a2a' => ['agent' => 'askmydocs', 'kind' => 'a2a'],
            '/.well-known/agent-card.json' => ['agent' => 'askmydocs', 'kind' => 'card'],
        ]);
    }
}
