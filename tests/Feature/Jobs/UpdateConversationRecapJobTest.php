<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\UpdateConversationRecapJob;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationRecapService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * UpdateConversationRecapJob: dispatch-time glue around
 * ConversationRecapService. Pins tenant scoping (R30), the missing-row race
 * (conversation deleted between dispatch and run), and that a failure
 * inside the service is logged, never rethrown into the queue worker.
 */
final class UpdateConversationRecapJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(string $tenantId = 'default'): Conversation
    {
        app(TenantContext::class)->set($tenantId);

        $user = User::create([
            'name' => 'Recap Job Tester',
            'email' => 'recap-job-'.uniqid().'@example.test',
            'password' => Hash::make('secret'),
        ]);

        return Conversation::create([
            'user_id' => $user->id,
            'project_key' => 'proj-recap',
        ]);
    }

    public function test_calls_the_service_for_an_existing_conversation(): void
    {
        $conversation = $this->makeConversation();

        $service = Mockery::mock(ConversationRecapService::class);
        $service->shouldReceive('updateAfterTurn')
            ->once()
            ->with(Mockery::on(fn (Conversation $c): bool => $c->id === $conversation->id));
        $this->app->instance(ConversationRecapService::class, $service);

        $job = new UpdateConversationRecapJob($conversation->id, 'default');
        $this->app->call([$job, 'handle']);
    }

    public function test_noop_when_the_conversation_no_longer_exists(): void
    {
        $service = Mockery::mock(ConversationRecapService::class);
        $service->shouldNotReceive('updateAfterTurn');
        $this->app->instance(ConversationRecapService::class, $service);

        $job = new UpdateConversationRecapJob(999999, 'default');
        $this->app->call([$job, 'handle']);
    }

    public function test_scoped_to_the_declared_tenant_r30(): void
    {
        $conversation = $this->makeConversation('tenant-a');

        $service = Mockery::mock(ConversationRecapService::class);
        $service->shouldNotReceive('updateAfterTurn');
        $this->app->instance(ConversationRecapService::class, $service);

        // Same conversation id, WRONG tenant — must resolve to nothing.
        $job = new UpdateConversationRecapJob($conversation->id, 'tenant-b');
        $this->app->call([$job, 'handle']);
    }

    public function test_a_failing_service_is_logged_not_rethrown(): void
    {
        $conversation = $this->makeConversation();

        $service = Mockery::mock(ConversationRecapService::class);
        $service->shouldReceive('updateAfterTurn')->once()->andThrow(new RuntimeException('provider timeout'));
        $this->app->instance(ConversationRecapService::class, $service);

        Log::shouldReceive('warning')
            ->once()
            ->with('UpdateConversationRecapJob: recap update failed', Mockery::on(
                fn (array $context): bool => $context['conversation_id'] === $conversation->id
                    && $context['error'] === 'provider timeout',
            ));

        $job = new UpdateConversationRecapJob($conversation->id, 'default');

        // Must not throw.
        $this->app->call([$job, 'handle']);
    }

    public function test_restores_the_previous_tenant_context_after_running(): void
    {
        $conversation = $this->makeConversation('tenant-a');
        app(TenantContext::class)->set('tenant-original');

        $service = Mockery::mock(ConversationRecapService::class);
        $service->shouldReceive('updateAfterTurn')->once();
        $this->app->instance(ConversationRecapService::class, $service);

        $job = new UpdateConversationRecapJob($conversation->id, 'tenant-a');
        $this->app->call([$job, 'handle']);

        $this->assertSame('tenant-original', app(TenantContext::class)->current());
    }
}
