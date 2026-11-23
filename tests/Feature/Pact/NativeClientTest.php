<?php

declare(strict_types=1);

namespace Tests\Feature\Pact;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentSessionWorkers;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Tools\AgentServerToolRunner;
use App\Agent\Tools\AgentToolRegistry;
use App\Http\Middleware\AuthorizeTenantHeader;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Pact\Native\ChatHandler;
use App\Pact\Native\ClientServiceProvider;
use App\Pact\Native\ConsentScope;
use App\Pact\Native\PendingConsent;
use App\Pact\Native\Subject;
use App\Pact\Native\SubjectResolver;
use App\Pact\Native\TaskAccess;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Pact\Pact;
use Pact\PactError;
use PactLaravel\Models\Connection;
use PactLaravel\Models\Member;
use PactLaravel\Models\OAuthClient;
use PactLaravel\Models\Task;
use PactLaravel\PactServiceProvider;
use PactLaravel\Runtime\TaskService;
use PactLaravel\Runtime\Worker;
use PHPUnit\Framework\Attributes\DataProvider;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

final class NativeClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 3);
        $sdk = $root.'/vendor/pact-protocol/laravel';
        foreach (glob($sdk.'/database/migrations/*.php') as $path) {
            (require $path)->up();
        }
        (require $root.'/database/migrations/2026_11_21_000001_create_pact_client_subjects.php')->up();
        (require $root.'/database/migrations/2026_11_23_000001_create_pact_client_consents.php')->up();
        config([
            'pact.enabled' => true,
            'pact.role' => 'client',
            'pact.url' => 'https://askmydocsdev.test',
            'pact.handler' => ChatHandler::class,
            'pact.contract_path' => $sdk.'/resources/contracts/agent-chat/contract.json',
            'pact.contract_registry' => $sdk.'/resources/contracts/agent-chat/registry.json',
        ]);
        $this->app->register(ClientServiceProvider::class);
        Queue::fake();
    }

    public function test_an_active_account_can_select_a_project_without_email_verification(): void
    {
        $parameters = $this->authorizeParameters();
        $subject = $this->subject();
        $user = User::query()->findOrFail($subject->user_id);
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAsWithoutTenant($user, 'web');

        $authorize = route('pact.oauth.authorize', $parameters);
        $choice = $this->get($authorize)->assertOk()->assertSee($subject->project_key);
        $this->selectProject($subject, $choice)->assertRedirect(route('pact.oauth.authorize', [
            ...$parameters, 'pact_consent' => $choice->viewData('consentId'),
        ]));
        $this->assertNotNull(app(SubjectResolver::class)->find((string) $subject->id));

        $this->get(route('pact.oauth.authorize', [
            ...$parameters,
            'redirect_uri' => 'https://master.test/other',
        ]))->assertForbidden();

        $user->forceFill(['is_active' => false])->save();
        $this->get($authorize)->assertForbidden();
        $this->assertNull(app(SubjectResolver::class)->find((string) $subject->id));
    }

    public function test_approval_restarts_project_selection_if_the_tenant_changed(): void
    {
        $parameters = $this->authorizeParameters();
        $oauth = $this->mockAuthorizationServer();
        $subject = $this->subject();
        $this->actingAsWithoutTenant(User::query()->findOrFail($subject->user_id), 'web');
        $authorize = route('pact.oauth.authorize', $parameters);
        $choice = $this->get($authorize)->assertOk();
        $consent = $this->openConsent($subject, $choice);
        $form = $this->consentFields($consent);
        $approve = route('pact.oauth.approve');
        $this->withSession([AuthorizeTenantHeader::ACTIVE_TENANT_SESSION_KEY => 'another-tenant'])
            ->post($approve, $form)
            ->assertStatus(303)
            ->assertRedirect($authorize)
            ->assertSessionMissing('authToken');
        $this->assertDatabaseMissing('pact_client_consents', ['id' => $form['pact_consent']]);

        $newChoice = $this->get($authorize)->assertOk()->assertSee('Scegli di nuovo il progetto');
        $newForm = $this->consentFields($this->openConsent($subject, $newChoice));
        $this->assertNotSame($form['auth_token'], $newForm['auth_token']);

        $callback = $parameters['redirect_uri'].'?code=example&state='.$parameters['state'];
        $oauth->expects($this->once())->method('completeAuthorizationRequest')
            ->with($this->callback(fn (AuthorizationRequest $request): bool => $request->isAuthorizationApproved()
                && $request->getUser()?->getIdentifier() === (string) $subject->id))
            ->willReturn(new Response(302, ['Location' => $callback]));
        $this->post($approve, $newForm)
            ->assertOk()->assertViewIs('pact-client.return')->assertViewHas('callback', $callback);
    }

    public function test_two_tabs_keep_their_project_selection_and_consent_independent(): void
    {
        $first = $this->authorizeParameters();
        $second = [...$first, 'state' => Str::random(40)];
        $oauth = $this->mockAuthorizationServer();
        $subjectA = $this->subject();
        ProjectMembership::query()->create([
            'user_id' => $subjectA->user_id, 'tenant_id' => 'other-tenant', 'project_key' => 'handbook', 'role' => 'member',
        ]);
        $subjectB = Subject::query()->create([
            'user_id' => $subjectA->user_id, 'tenant_id' => 'other-tenant', 'project_key' => 'handbook',
        ]);
        $this->actingAsWithoutTenant(User::query()->findOrFail($subjectA->user_id), 'web');

        $choiceA = $this->get(route('pact.oauth.authorize', $first))->assertOk();
        $choiceB = $this->get(route('pact.oauth.authorize', $second))->assertOk();
        $selectedA = $this->selectProject($subjectA, $choiceA)->assertStatus(303)->headers->get('Location');
        $formA = $this->consentFields($this->get($selectedA)->assertOk()->assertViewIs('pact::consent'));
        $formB = $this->consentFields($this->openConsent($subjectB, $choiceB));
        $this->assertSame($formA, $this->consentFields($this->get($selectedA)->assertOk()));

        $approve = route('pact.oauth.approve');
        $this->post($approve, [...$formA, 'auth_token' => $formB['auth_token']])->assertConflict();
        $sessionId = session()->getId();
        $this->withCookie(config('session.cookie'), Str::random(40))->post($approve, $formA)->assertConflict();
        $this->withCookie(config('session.cookie'), $sessionId);
        $oauth->expects($this->exactly(2))->method('completeAuthorizationRequest')
            ->willReturnCallback(function (AuthorizationRequest $request) use ($first, $second, $subjectA, $subjectB): Response {
                $this->assertTrue($request->isAuthorizationApproved());
                $expected = match ($request->getState()) {
                    $first['state'] => $subjectA,
                    $second['state'] => $subjectB,
                };
                $this->assertSame((string) $expected->id, $request->getUser()?->getIdentifier());

                return new Response(302, ['Location' => $first['redirect_uri'].'?state='.$request->getState()]);
            });
        $this->post($approve, $formA)->assertOk()->assertViewIs('pact-client.return')->assertSessionMissing('authToken');
        $this->post($approve, $formA)->assertConflict();
        $this->post($approve, $formB)->assertOk()->assertViewIs('pact-client.return')->assertSessionMissing('authToken');
    }

    public function test_expired_consent_and_a_bare_authorization_url_explain_how_to_restart(): void
    {
        $authorize = route('pact.oauth.authorize', $this->authorizeParameters());
        $this->mockAuthorizationServer()->expects($this->never())->method('completeAuthorizationRequest');
        $subject = $this->subject();
        $this->actingAsWithoutTenant(User::query()->findOrFail($subject->user_id), 'web');
        $choice = $this->get($authorize)->assertOk();
        $form = $this->consentFields($this->openConsent($subject, $choice));

        $this->travel(11)->minutes();
        $this->post(route('pact.oauth.approve'), $form)
            ->assertConflict()->assertViewIs('pact-client.restart')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('pact.oauth.authorize'))->assertBadRequest()->assertViewIs('pact-client.restart');
        $this->get($authorize)->assertOk();
        $this->assertDatabaseMissing((new PendingConsent)->getTable(), ['id' => $form['pact_consent']]);
    }

    public function test_diagnostic_ping_does_not_enqueue_an_askmydocs_turn(): void
    {
        config(['pact.handler' => null, 'pact.contract_path' => null, 'pact.contract_registry' => null]);
        $connection = $this->connection($this->subject());
        $task = $this->submit($connection, ['message' => 'proof of connectivity']);

        $this->assertTrue(app(Worker::class)->workOnce());
        $task->refresh();
        $this->assertSame('TASK_STATE_COMPLETED', $task->state);
        $this->assertSame(['pong' => true, 'message' => 'proof of connectivity'], data_get($task->value, 'artifacts.0.parts.0.data'));
        Queue::assertNothingPushed();
    }

    public function test_scoped_turn_is_idempotent_and_answers_a_correlated_clarification(): void
    {
        config(['agent.queue' => 'shared-agent']);
        $subject = $this->subject();
        $connection = $this->connection($subject);
        $task = $this->submit($connection, [
            'message' => 'Quale guida descrive questo passaggio?',
            'coordinator' => ['endpoint' => 'https://master.test/pact/mcp', 'federation_id' => $connection->federation_id, 'member_id' => $connection->member_id],
        ]);
        $this->assertTrue(app(Worker::class)->workOnce());
        $run = AgentRun::query()->forTenant($subject->tenant_id)->sole();
        $this->assertSame((int) $subject->user_id, (int) $run->user_id);
        $this->assertSame($subject->project_key, $run->project_key);
        $this->assertSame($task->id, $run->input_json['pact_client_task_id']);
        Queue::assertPushedOn('shared-agent', ExecuteAgentRunJob::class);

        // Deliver the poll after the initial PACT lease has completed.
        $this->travel(4)->seconds();
        $this->assertTrue(app(Worker::class)->workOnce());
        $this->assertSame(1, AgentRun::query()->forTenant($subject->tenant_id)->count());
        $run->update(['status' => AgentRun::STATUS_PARTIAL, 'result_json' => ['response' => [
            'answer' => 'A quale guida ti riferisci?',
            'grounding' => ['reason' => 'focus_ambiguous'],
        ]]]);

        $this->travel(4)->seconds();
        $this->assertTrue(app(Worker::class)->workOnce());
        $task->refresh();
        $this->assertSame('TASK_STATE_INPUT_REQUIRED', $task->state);
        $question = $task->value['metadata'][Pact::CORE_URI]['questions'][0];
        $this->rpc($connection, 'SendMessage', ['message' => [
            'role' => 'ROLE_USER', 'messageId' => 'follow-up', 'taskId' => $task->id,
            'contextId' => $task->value['contextId'], 'parts' => [['data' => ['message' => 'Guida di installazione']]],
            'metadata' => [Pact::CORE_URI => $this->metadata('follow-up') + ['answers' => [[
                'response_to' => $question['question_id'], 'task_id' => $task->id, 'value' => ['text' => 'Guida di installazione'],
            ]]]],
        ]]);
        $this->assertTrue(app(Worker::class)->workOnce());
        $followup = AgentRun::query()->forTenant($subject->tenant_id)->where('id', '!=', $run->id)->sole();
        $this->assertSame($run->conversation_id, $followup->conversation_id);
        $this->assertSame('Guida di installazione', $followup->input_json['question']);
        Queue::assertPushedOn('shared-agent', ExecuteAgentRunJob::class, fn ($job) => $job->agentRunId === $followup->id);
        $followup->update(['status' => AgentRun::STATUS_COMPLETED, 'result_json' => ['response' => [
            'answer' => 'Consulta il capitolo Installazione.',
            'citations' => [['title' => 'Guida di installazione']],
        ]]]);

        $this->travel(4)->seconds();
        $this->assertTrue(app(Worker::class)->workOnce());
        $task->refresh();
        $this->assertSame('TASK_STATE_COMPLETED', $task->state);
        $this->assertSame([
            'text' => 'Consulta il capitolo Installazione.',
            'sources' => [['title' => 'Guida di installazione']],
        ], data_get($task->value, 'artifacts.0.parts.0.data'));
    }

    public static function sharedQueues(): array
    {
        return [
            'session workers available' => [true, 'shared-session', 'conversation'],
            'serial fallback' => [false, 'shared-agent', null],
        ];
    }

    #[DataProvider('sharedQueues')]
    public function test_a_new_task_uses_the_shared_queue_after_commit(bool $sessionWorkers, string $queue, ?string $stage): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => false,
            'agent.waves.enabled' => true,
            'agent.concurrent_session' => true,
            'agent.verify_session_workers' => true,
            'agent.session_queue' => 'shared-session',
            'agent.queue' => 'shared-agent',
        ]);
        Queue::except(ExecuteAgentRunJob::class);
        if ($sessionWorkers) {
            (new AgentSessionWorkers)->observe(new Looping('database', 'shared-session'));
            (new AgentSessionWorkers)->observe(new Looping('database', 'shared-agent'));
        }

        $subject = $this->subject();
        $connection = $this->connection($subject);
        $this->submit($connection, [
            'message' => 'Qual è la risposta?',
            'coordinator' => ['endpoint' => 'https://master.test/pact/mcp', 'federation_id' => $connection->federation_id, 'member_id' => $connection->member_id],
        ]);

        DB::transaction(function (): void {
            $this->assertTrue(app(Worker::class)->workOnce());
            $this->assertDatabaseEmpty('jobs');
        });

        $run = AgentRun::query()->forTenant($subject->tenant_id)->sole();
        $message = DB::table('jobs')->sole();
        $job = unserialize(json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR)['data']['command']);
        $this->assertSame($queue, $message->queue);
        $this->assertSame($stage, $job->sessionStage);
        $this->assertSame($run->id, $job->agentRunId);
        $this->assertNull($job->connection);
    }

    public function test_a_failed_queue_publish_does_not_leave_a_pact_run_waiting(): void
    {
        Queue::beforePushing(function (object $job): void {
            if ($job instanceof ExecuteAgentRunJob) {
                throw new \RuntimeException('Queue unavailable');
            }
        });

        $subject = $this->subject();
        $connection = $this->connection($subject);
        $task = $this->submit($connection, [
            'message' => 'Qual è la risposta?',
            'coordinator' => ['endpoint' => 'https://master.test/pact/mcp', 'federation_id' => $connection->federation_id, 'member_id' => $connection->member_id],
        ]);
        $this->assertTrue(app(Worker::class)->workOnce());
        $run = AgentRun::query()->forTenant($subject->tenant_id)->sole();
        $this->assertSame(AgentRun::STATUS_FAILED, $run->status);
        $this->assertSame('session_dispatch_failed', $run->error_code);
        Queue::assertNotPushed(ExecuteAgentRunJob::class);

        $this->travel(4)->seconds();
        $this->assertTrue(app(Worker::class)->workOnce());
        $this->assertSame('TASK_STATE_FAILED', $task->refresh()->state);
    }

    public function test_a_foreign_connection_cannot_read_a_task_and_removed_membership_blocks_work(): void
    {
        $subject = $this->subject();
        $connection = $this->connection($subject);
        $other = $this->connection($this->subject());
        $task = $this->submit($connection, [
            'message' => 'Qual è la risposta?',
            'coordinator' => ['endpoint' => 'https://master.test/pact/mcp', 'federation_id' => $connection->federation_id, 'member_id' => $connection->member_id],
        ]);
        try {
            $this->rpc($other, 'GetTask', ['id' => $task->id]);
            $this->fail('A different subject must not see this task.');
        } catch (PactError $error) {
            $this->assertSame('TASK_NOT_FOUND', $error->errorCode);
        }

        ProjectMembership::query()->forTenant($subject->tenant_id)->where('user_id', $subject->user_id)->delete();
        $this->assertNull(app(SubjectResolver::class)->find((string) $subject->id));
        $this->assertTrue(app(Worker::class)->workOnce());
        $this->assertSame('TASK_STATE_FAILED', $task->refresh()->state);
        Queue::assertNothingPushed();

        $connection->update(['status' => 'revoked']);
        $this->assertFalse(app(TaskAccess::class)->permits($task));
    }

    public function test_an_unavailable_agent_reports_a_retryable_error(): void
    {
        $subject = $this->subject();
        $connection = $this->connection($subject);
        $task = $this->submit($connection, [
            'message' => 'Qual è la risposta?',
            'coordinator' => ['endpoint' => 'https://master.test/pact/mcp', 'federation_id' => $connection->federation_id, 'member_id' => $connection->member_id],
        ]);
        $this->assertTrue(app(Worker::class)->workOnce());
        AgentRun::query()->forTenant($subject->tenant_id)->sole()->update([
            'status' => AgentRun::STATUS_PARTIAL,
            'result_json' => ['response' => [
                'answer' => 'Riprova tra poco.',
                'grounding' => ['reason' => 'question_interpretation_unavailable'],
            ]],
        ]);

        $this->travel(4)->seconds();
        $this->assertTrue(app(Worker::class)->workOnce());
        $this->assertSame('TASK_STATE_FAILED', $task->refresh()->state);
        $error = $task->value['metadata'][Pact::CORE_URI]['error'];
        $this->assertSame('AGENT_UNAVAILABLE', $error['code']);
        $this->assertTrue($error['retryable']);
    }

    public function test_reauthorization_cannot_restart_work_from_a_previous_generation(): void
    {
        $connection = $this->connection($this->subject());
        $connection->update(['metadata' => ['generation' => 1]]);
        $task = $this->submit($connection, [
            'message' => 'Quale guida descrive questo passaggio?',
            'coordinator' => ['endpoint' => 'https://master.test/pact/mcp', 'federation_id' => $connection->federation_id, 'member_id' => $connection->member_id],
        ]);
        $connection->update(['metadata' => ['generation' => 2]]);

        $this->assertTrue(app(Worker::class)->workOnce());
        $this->assertSame('TASK_STATE_FAILED', $task->refresh()->state);
        Queue::assertNothingPushed();
    }

    public function test_only_authorized_idempotent_api_reads_are_available_and_executed(): void
    {
        config(['connector-api.ssrf.resolve_dns' => false]);
        Http::fake(['api.example.test/*' => Http::response(['data' => [['id' => 'manual-1']]])]);
        $subject = $this->subject();
        $connection = $this->connection($subject);
        $this->submit($connection, [
            'message' => 'Controlla il catalogo.',
            'coordinator' => ['endpoint' => 'https://master.test/pact/mcp', 'federation_id' => $connection->federation_id, 'member_id' => $connection->member_id],
        ]);
        $this->assertTrue(app(Worker::class)->workOnce());
        $run = AgentRun::query()->forTenant($subject->tenant_id)->sole();
        $context = new AgentExecutionContext($run->run_id, $run->tenant_id, $run->project_key, $run->channel,
            $run->actor_type, $run->actor_id, $run->locale, $run->timezone);
        $context->activate();
        $read = $this->apiRoute($subject, 'read_manuals', 'GET');
        $this->apiRoute($subject, 'edit_manuals', 'POST');
        $this->apiRoute($subject, 'foreign_manuals', 'GET', 'private-project');

        $tools = app(AgentToolRegistry::class)->forResearch($run, $context);
        $this->assertArrayHasKey('read_manuals', $tools);
        $this->assertArrayNotHasKey('edit_manuals', $tools);
        $this->assertArrayNotHasKey('foreign_manuals', $tools);
        $this->assertArrayNotHasKey('read_private_session', $tools);

        $runner = app(AgentServerToolRunner::class);
        $budget = new AgentBudgetTracker($run);
        $result = $runner->execute($tools['read_manuals'], [], $context, $run, $budget);
        $this->assertSame([['id' => 'manual-1']], $result->body['data'] ?? null, (string) ($result->body['error'] ?? ''));
        $read->update(['http_method' => 'POST']);

        $this->expectExceptionMessage('api_tool_dependency_unavailable');
        try {
            $runner->execute($tools['read_manuals'], [], $context, $run, $budget);
        } finally {
            Http::assertSentCount(1);
        }
    }

    private function mockAuthorizationServer(): AuthorizationServer
    {
        $server = $this->createMock(AuthorizationServer::class);
        $server->method('validateAuthorizationRequest')->willReturnCallback(static function (ServerRequestInterface $request): AuthorizationRequest {
            $authorization = new AuthorizationRequest;
            $authorization->setGrantTypeId('authorization_code');
            $authorization->setState($request->getQueryParams()['state']);

            return $authorization;
        });
        $this->app->instance(AuthorizationServer::class, $server);

        return $server;
    }

    private function authorizeParameters(): array
    {
        config(['pact.auth.middleware' => [ConsentScope::class]]);
        $this->app->register(PactServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->withCookie(config('session.cookie'), Str::random(40));

        $client = OAuthClient::query()->create([
            'oauth_client_id' => (string) Str::uuid(),
            'master_url' => 'https://master.test',
            'callback_url' => 'https://master.test/pact/oauth/callback',
        ]);

        return [
            'client_id' => $client->oauth_client_id,
            'redirect_uri' => $client->callback_url,
            'response_type' => 'code',
            'state' => Str::random(40),
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ];
    }

    private function openConsent(Subject $subject, TestResponse $choice): TestResponse
    {
        $selected = $this->selectProject($subject, $choice)->assertStatus(303);

        return $this->get($selected->headers->get('Location'))->assertOk()->assertViewIs('pact::consent');
    }

    private function consentFields(TestResponse $consent): array
    {
        return ['pact_consent' => $consent->viewData('consentId'), 'auth_token' => $consent->viewData('authToken')];
    }

    private function selectProject(Subject $subject, TestResponse $choice): TestResponse
    {
        return $this->post(route('pact.oauth.select-project'), [
            'pact_consent' => $choice->viewData('consentId'),
            'project' => hash('sha256', $subject->tenant_id."\0".$subject->project_key),
        ]);
    }

    private function apiRoute(Subject $subject, string $name, string $method, ?string $project = null): ApiRoute
    {
        $project ??= $subject->project_key;
        $connector = ApiConnector::query()->create([
            'tenant_id' => $subject->tenant_id, 'project_key' => $project, 'name' => $name, 'is_active' => true,
        ]);

        return ApiRoute::query()->create([
            'tenant_id' => $subject->tenant_id, 'project_key' => $project, 'api_connector_id' => $connector->id,
            'name' => $name, 'slug' => $name, 'http_method' => $method, 'url' => 'https://api.example.test/'.$name,
            'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'list', 'items_path' => 'data',
        ]);
    }

    private function subject(): Subject
    {
        $user = User::query()->create(['name' => 'PACT QA', 'email' => Str::uuid().'@example.test', 'password' => Str::random(32)]);
        $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
        ProjectMembership::query()->create([
            'tenant_id' => 'pact-qa', 'project_key' => 'manuals', 'user_id' => $user->id, 'role' => 'member',
        ]);

        return Subject::query()->create(['tenant_id' => 'pact-qa', 'project_key' => 'manuals', 'user_id' => $user->id]);
    }

    private function connection(Subject $subject): Connection
    {
        $connection = Connection::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => (string) $subject->id, 'federation_id' => (string) Str::uuid(),
            'member_id' => 'agent-'.Str::uuid(), 'remote_url' => 'https://master.test', 'remote_user_id' => 'qa', 'status' => 'active',
        ]);
        Member::query()->create([
            'connection_id' => $connection->id, 'federation_id' => $connection->federation_id,
            'member_id' => $connection->member_id, 'user_id' => $connection->user_id, 'active' => true,
        ]);

        return $connection;
    }

    private function submit(Connection $connection, array $input): Task
    {
        $params = ['message' => [
            'role' => 'ROLE_USER', 'messageId' => 'test-message', 'parts' => [['data' => $input]],
            'metadata' => [Pact::CORE_URI => $this->metadata('original')],
        ]];
        $response = $this->rpc($connection, 'SendMessage', $params);
        $this->assertSame($response, $this->rpc($connection, 'SendMessage', $params));

        return Task::query()->findOrFail($response['task']['id']);
    }

    private function metadata(string $key): array
    {
        return ['version' => Pact::VERSION, 'contract' => app(TaskService::class)->contract(), 'idempotency_key' => $key];
    }

    private function rpc(Connection $connection, string $method, array $params): array
    {
        $raw = json_encode(['jsonrpc' => '2.0', 'id' => $method, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);

        return app(TaskService::class)->rpc($connection, $connection->member_id, json_decode($raw, false, 512, JSON_THROW_ON_ERROR), $raw);
    }
}
