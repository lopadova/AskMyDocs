<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentExecutionContextFactory;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

final class AgentExecutionContextTest extends TestCase
{
    public function test_user_locale_is_pinned_when_the_context_is_created(): void
    {
        $user = new User(['locale' => 'it-IT']);
        $user->id = 42;
        app(TenantContext::class)->set('acme');

        $context = app(AgentExecutionContextFactory::class)->forUser($user, 'orders');
        $user->locale = 'en-US';

        $this->assertSame('it-IT', $context->locale);
        $this->assertSame('acme', $context->tenantId);
        $this->assertSame('orders', $context->projectKey);
        $this->assertSame('42', $context->actorId);
    }

    public function test_context_round_trips_through_a_queue_safe_array(): void
    {
        $original = new AgentExecutionContext(
            runId: 'run-123',
            tenantId: 'acme',
            projectKey: 'orders',
            channel: 'chat',
            actorType: 'user',
            actorId: '42',
            locale: 'it-IT',
            timezone: 'Europe/Rome',
        );

        $restored = AgentExecutionContext::fromArray($original->jsonSerialize());

        $this->assertEquals($original, $restored);
    }

    public function test_activating_a_restored_context_selects_the_matching_catalog(): void
    {
        App::setLocale('en');
        $context = AgentExecutionContext::fromArray([
            'run_id' => 'run-123',
            'tenant_id' => 'acme',
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => '42',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
        ]);

        $context->activate();

        $this->assertSame('it', App::currentLocale());
        $this->assertSame('Europe/Rome', date_default_timezone_get());
    }
}
