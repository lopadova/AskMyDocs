<?php

declare(strict_types=1);

namespace Tests\Feature\ApiTools;

use App\Agent\AgentExecutionContext;
use App\ApiTools\ContentHasher;
use App\ApiTools\Jobs\VerifyApiTool;
use App\ApiTools\Jobs\SweepApiTools;
use App\ApiTools\Models\ApiTool;
use App\ApiTools\Models\ApiToolRevision;
use App\ApiTools\Models\ApiToolRun;
use App\ApiTools\Models\ApiToolVerification;
use App\ApiTools\ToolLifecycle;
use App\ApiTools\ToolPackageService;
use App\ApiTools\ToolVerificationService;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Tests\TestCase;

final class ApiToolLifecyclePackageTest extends TestCase
{
    use RefreshDatabase;

    private AgentExecutionContext $context;
    private ApiRoute $route;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \App\Providers\ApiToolServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations/2026_10_26_160000_create_api_tools_tables.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $tenant = app(TenantContext::class)->current();
        $user = User::query()->create(['name' => 'Tool owner', 'email' => 'owner@example.test', 'password' => bcrypt('test-password')]);
        $user->assignRole('admin');
        ProjectMembership::query()->create(['tenant_id' => $tenant, 'user_id' => $user->id, 'project_key' => 'sales', 'role' => 'owner']);
        $this->context = new AgentExecutionContext((string) Str::uuid(), $tenant, 'sales', 'chat', 'user', (string) $user->id, 'en', 'UTC');
        $this->context->activate();
        $connector = ApiConnector::query()->create(['tenant_id' => $tenant, 'project_key' => 'sales', 'name' => 'Sales API', 'base_url' => 'https://api.example.com', 'is_active' => true]);
        $this->route = ApiRoute::query()->create([
            'tenant_id' => $tenant, 'project_key' => 'sales', 'api_connector_id' => $connector->id,
            'name' => 'Orders', 'slug' => 'orders', 'http_method' => 'GET', 'url' => 'https://api.example.com/orders', 'mode' => 'tool', 'status' => 'active',
            'input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
        ]);
    }

    public function test_drafts_have_immutable_revisions_and_cannot_publish_without_current_checks(): void
    {
        $tool = $this->draft();
        $first = $tool->revision;
        $changed = $this->definition();
        $changed['output']['constant'] = ['source' => 'constant', 'value' => true];
        $tool = app(ToolLifecycle::class)->saveDraft($tool, ['definition' => $changed], $this->context);
        $this->assertSame(2, $tool->revision->number);
        $this->assertSame($first->definition, $first->fresh()->definition);
        $this->assertNotSame($first->id, $tool->current_revision_id);
        $this->expectException(ValidationException::class);
        app(ToolLifecycle::class)->publish($tool, $this->context);
    }

    public function test_failure_threshold_is_idempotent_ignores_test_and_budget_failures_and_success_resets(): void
    {
        Bus::fake([VerifyApiTool::class]);
        $tool = $this->draft();
        $tool->update(['status' => 'active', 'publication_intent' => true]);
        $service = app(ToolLifecycle::class);
        $failed = $this->fixtureRun($tool, 'failed');
        $service->recordOutcome($failed);
        $service->recordOutcome($failed);
        $this->assertSame(1, $tool->fresh()->consecutive_failures);
        $service->recordOutcome($this->fixtureRun($tool, 'failed', 'request_budget_exceeded'));
        $service->recordOutcome($this->fixtureRun($tool, 'failed', null, 'verification'));
        $this->assertSame(1, $tool->fresh()->consecutive_failures);
        $service->recordOutcome($this->fixtureRun($tool, 'completed'));
        $this->assertSame(0, $tool->fresh()->consecutive_failures);
        $service->recordOutcome($this->fixtureRun($tool, 'failed'));
        $service->recordOutcome($this->fixtureRun($tool, 'partial'));
        $this->assertSame(0, $tool->fresh()->consecutive_failures);
        for ($i = 0; $i < 3; $i++) { $service->recordOutcome($this->fixtureRun($tool, 'failed')); }
        $this->assertSame('disabled_errors', $tool->fresh()->status);
        $this->assertDatabaseCount('api_tool_state_events', 1);
        $this->assertDatabaseCount('notification_events', 1);
        Bus::assertDispatched(VerifyApiTool::class, fn ($job) => $job->auto && $job->context['actor_id'] === $this->context->actorId);
    }

    public function test_dependency_changes_deprecate_and_queue_verification_while_manual_disable_prevails(): void
    {
        Bus::fake([VerifyApiTool::class]);
        $tool = $this->draft();
        $tool->update(['status' => 'active', 'publication_intent' => true]);
        $this->route->update(['url' => '/v2/orders']);
        $this->assertFalse(app(ToolLifecycle::class)->checkDependencies($tool, $this->context));
        $this->assertSame('deprecated', $tool->fresh()->status);
        Bus::assertDispatched(VerifyApiTool::class);
        app(ToolLifecycle::class)->disable($tool->fresh(), $this->context);
        $this->route->update(['url' => '/v3/orders']);
        app(ToolLifecycle::class)->checkDependencies($tool->fresh(), $this->context);
        $this->assertSame('disabled_manual', $tool->fresh()->status);
    }

    public function test_old_live_completion_and_manual_off_cannot_reactivate_a_tool(): void
    {
        $tool = $this->draft();
        $tool->update(['publication_intent' => true, 'status' => 'deprecated']);
        [$verification, $run] = $this->liveVerification($tool);
        app(ToolLifecycle::class)->disable($tool, $this->context);
        app(ToolVerificationService::class)->consumeRun($run);
        $this->assertSame('stale', $verification->fresh()->status);
        $this->assertSame('disabled_manual', $tool->fresh()->status);
    }

    public function test_live_proof_requires_at_least_one_physical_request(): void
    {
        $tool = $this->draft();
        [$verification, $run] = $this->liveVerification($tool);
        $run->update(['physical_requests' => 0]);
        app(ToolVerificationService::class)->consumeRun($run->fresh());
        $this->assertSame('failed', $verification->fresh()->status);
    }

    public function test_matching_checks_publish_and_any_dependency_drift_blocks_republication(): void
    {
        $tool = $this->draft();
        [$verification, $run] = $this->liveVerification($tool);
        foreach (['static', 'simulation'] as $mode) {
            $attributes = $verification->getAttributes();
            unset($attributes['id']);
            $attributes['mode'] = $mode;
            $attributes['status'] = 'passed';
            // Do not copy encrypted raw storage through the cast a second time.
            $attributes['results'] = [];
            ApiToolVerification::query()->create($attributes);
        }
        app(ToolVerificationService::class)->consumeRun($run);
        $tool = app(ToolLifecycle::class)->publish($tool->fresh(), $this->context);
        $this->assertTrue($tool->available());
        $this->route->update(['url' => '/changed']);
        $this->assertFalse(app(ToolVerificationService::class)->isVerified($tool, $this->context));
    }

    public function test_package_roundtrip_uses_portable_references_and_creates_a_new_unpublished_draft(): void
    {
        $tool = $this->draft();
        $tool = app(ToolLifecycle::class)->saveDraft($tool, ['definition' => $this->definition(), 'failure_threshold' => 7], $this->context);
        $package = app(ToolPackageService::class)->export($tool);
        $this->assertSame('askmydocs.api-tool', $package['_meta']['format']);
        $this->assertArrayNotHasKey('route_id', $package['tool']['definition']['steps'][0]);
        $this->assertSame('Sales API', $package['dependencies'][0]['connector']);
        $copy = app(ToolPackageService::class)->import($package, 'sales', $this->context);
        $this->assertNotSame($tool->id, $copy->id);
        $this->assertSame('Order report (2)', $copy->name);
        $this->assertSame('draft', $copy->status);
        $this->assertFalse($copy->publication_intent);
        $this->assertSame(7, $copy->failure_threshold);
        $this->assertSame($this->route->id, $copy->revision->definition['steps'][0]['route_id']);
    }

    public function test_package_rejects_credential_copied_to_prose_and_cross_project_dependencies(): void
    {
        $tool = $this->draft();
        $package = app(ToolPackageService::class)->export($tool);
        $profile = ApiAuthProfile::query()->create(['tenant_id' => $tool->tenant_id, 'api_connector_id' => $this->route->api_connector_id, 'type' => 'bearer', 'credentials' => ['token' => 'very-private-test-token']]);
        $this->route->connector->update(['default_auth_profile_id' => $profile->id]);
        $package['tool']['description'] = 'very-private-test-token';
        try {
            app(ToolPackageService::class)->import($package, 'sales', $this->context);
            $this->fail('Copied credential was accepted.');
        } catch (ValidationException) { $this->assertDatabaseCount('api_tools', 1); }
        $package['tool']['description'] = null;
        $this->route->update(['project_key' => 'private']);
        $this->expectException(ValidationException::class);
        app(ToolPackageService::class)->import($package, 'sales', $this->context);
    }

    public function test_live_suite_queues_one_case_at_a_time_and_duplicate_completion_does_not_advance_twice(): void
    {
        config()->set('api-tools.enabled', true);
        Bus::fake([\Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob::class, SweepApiTools::class, VerifyApiTool::class]);
        $definition = $this->definition();
        $definition['tests'][] = [...$definition['tests'][1], 'name' => 'second live'];
        $tool = app(ToolLifecycle::class)->saveDraft(null, ['name' => 'Async cases', 'definition' => $definition], $this->context);
        $records = app(ToolVerificationService::class)->start($tool, $this->context);
        $this->assertSame(['passed', 'passed', 'running'], array_map(fn ($v) => $v->status, $records));
        $this->assertDatabaseCount('api_tool_runs', 1);
        $first = ApiToolRun::query()->firstOrFail();
        $first->update(['status' => 'completed', 'physical_requests' => 1, 'outputs' => ['items' => [1]], 'completed_at' => now()]);
        app(ToolVerificationService::class)->consumeRun($first->fresh());
        app(ToolVerificationService::class)->consumeRun($first->fresh());
        $this->assertDatabaseCount('api_tool_runs', 2);
        $second = ApiToolRun::query()->whereKeyNot($first->id)->firstOrFail();
        $second->update(['status' => 'completed', 'physical_requests' => 1, 'outputs' => ['items' => [1]], 'completed_at' => now()]);
        app(ToolVerificationService::class)->consumeRun($second->fresh());
        $this->assertSame('passed', $records[2]->fresh()->status);
        $this->assertSame('draft', $tool->fresh()->status);
        $this->assertTrue(app(ToolLifecycle::class)->publish($tool->fresh(), $this->context)->available());
    }

    public function test_return_to_a_previous_hash_still_requires_a_fresh_live_probe(): void
    {
        config()->set('api-tools.enabled', true);
        Bus::fake([\Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob::class, SweepApiTools::class, VerifyApiTool::class]);
        $definition = $this->definition();
        $definition['tests'][] = [...$definition['tests'][1], 'name' => 'second live'];
        $tool = app(ToolLifecycle::class)->saveDraft(null, ['name' => 'Historical checks', 'definition' => $definition], $this->context);
        $records = app(ToolVerificationService::class)->start($tool, $this->context);
        for ($i = 0; $i < 2; $i++) {
            $run = ApiToolRun::query()->where('status', 'running')->firstOrFail();
            $run->update(['status' => 'completed', 'physical_requests' => 1, 'outputs' => ['items' => [1]], 'completed_at' => now()]);
            app(ToolVerificationService::class)->consumeRun($run->fresh());
        }
        $tool = app(ToolLifecycle::class)->publish($tool->fresh(), $this->context);
        $original = $tool->content_hash;
        foreach (['https://api.example.com/b/orders', 'https://api.example.com/c/orders', 'https://api.example.com/orders'] as $url) {
            $this->route->update(['url' => $url]);
            app(ToolLifecycle::class)->checkDependencies($tool->fresh(), $this->context);
        }
        $tool->refresh();
        $this->assertSame($original, $tool->content_hash);
        $this->assertSame('deprecated', $tool->status);
        $records = app(ToolVerificationService::class)->start($tool, $this->context, 'full', true);
        $live = $records[2];
        $this->assertTrue($live->results['brief']);
        $this->assertCount(1, $live->results['definitions']);
        $this->assertSame('deprecated', $tool->fresh()->status);
        $run = ApiToolRun::query()->where('status', 'running')->firstOrFail();
        $run->update(['status' => 'completed', 'physical_requests' => 1, 'outputs' => ['items' => [1]], 'completed_at' => now()]);
        app(ToolVerificationService::class)->consumeRun($run->fresh());
        $this->assertTrue($tool->fresh()->available());
    }

    public function test_full_suite_live_case_executes_the_connector_and_counts_the_wire_request(): void
    {
        config()->set('api-tools.enabled', true);
        config()->set('connector-api.ssrf.resolve_dns', false);
        Bus::fake([\Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob::class, SweepApiTools::class]);
        Http::fake(['api.example.com/*' => Http::response(['items' => [1]])]);
        $tool = $this->draft();
        $records = app(ToolVerificationService::class)->start($tool, $this->context);
        $this->assertSame('running', $records[2]->status);
        $run = ApiToolRun::query()->firstOrFail();
        $runtime = app(\App\ApiTools\ToolRuntime::class);
        $runtime->executeStep($run->id, 'orders');
        $runtime->executeStep($run->id, 'tool_output');
        $runtime->finish($run->id, 'completed');
        $this->assertSame(1, $run->fresh()->physical_requests);
        $this->assertSame('passed', $records[2]->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_global_tool_reconciliation_uses_its_owner_context_and_ignores_one_revoked_viewer(): void
    {
        Bus::fake([VerifyApiTool::class]);
        $this->route->update(['project_key' => '']);
        $this->route->connector->update(['project_key' => '']);
        $global = new AgentExecutionContext((string) Str::uuid(), $this->context->tenantId, null, 'chat', 'user', $this->context->actorId, 'en', 'UTC');
        $tool = app(ToolLifecycle::class)->saveDraft(null, ['name' => 'Global report', 'definition' => $this->definition()], $global);
        $tool->update(['status' => 'active', 'publication_intent' => true]);
        $viewer = User::query()->create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => bcrypt('password')]);
        $viewer->assignRole('viewer');
        ProjectMembership::query()->create(['tenant_id' => $this->context->tenantId, 'user_id' => $viewer->id, 'project_key' => 'sales', 'role' => 'viewer']);
        $viewerContext = new AgentExecutionContext((string) Str::uuid(), $this->context->tenantId, 'sales', 'chat', 'user', (string) $viewer->id, 'en', 'UTC');
        $this->assertTrue(app(ToolLifecycle::class)->checkDependencies($tool, $viewerContext));
        ProjectMembership::query()->where('user_id', $viewer->id)->delete();
        $this->assertFalse(app(ToolLifecycle::class)->checkDependencies($tool, $viewerContext));
        $this->assertSame('active', $tool->fresh()->status);
        ProjectMembership::query()->create(['tenant_id' => $this->context->tenantId, 'user_id' => $viewer->id, 'project_key' => 'sales', 'role' => 'viewer']);
        $this->route->update(['url' => 'https://api.example.com/changed']);
        app(ToolLifecycle::class)->checkDependencies($tool, $viewerContext);
        Bus::assertDispatched(VerifyApiTool::class, fn ($job) => $job->context['actor_id'] === $this->context->actorId && $job->context['project_key'] === null);
    }

    public function test_editing_a_global_tool_from_a_project_cannot_add_project_local_dependencies(): void
    {
        $this->route->update(['project_key' => '']);
        $this->route->connector->update(['project_key' => '']);
        $global = new AgentExecutionContext((string) Str::uuid(), $this->context->tenantId, null, 'chat', 'user', $this->context->actorId, 'en', 'UTC');
        $tool = app(ToolLifecycle::class)->saveDraft(null, ['name' => 'Global report', 'definition' => $this->definition()], $global);
        $local = $this->route->replicate();
        $local->project_key = 'sales';
        $local->slug = 'local_orders';
        $local->save();
        $definition = $this->definition();
        $definition['steps'][0]['route_id'] = $local->id;
        $this->expectException(ValidationException::class);
        app(ToolLifecycle::class)->saveDraft($tool, ['definition' => $definition], $this->context);
    }

    public function test_individual_checks_bump_generation_on_each_dependency_change(): void
    {
        $tool = $this->draft();
        $original = $tool->content_hash;
        $generation = $tool->verification_generation;
        $this->route->update(['url' => 'https://api.example.com/b/orders']);
        app(ToolVerificationService::class)->start($tool, $this->context, 'static');
        $this->assertSame($generation + 1, $tool->fresh()->verification_generation);
        $this->route->update(['url' => 'https://api.example.com/orders']);
        app(ToolVerificationService::class)->start($tool->fresh(), $this->context, 'static');
        $this->assertSame($generation + 2, $tool->fresh()->verification_generation);
        $this->assertSame($original, $tool->fresh()->content_hash);
        $this->assertFalse(app(ToolVerificationService::class)->isVerified($tool->fresh(), $this->context));
    }

    public function test_invalid_failure_threshold_rolls_back_the_entire_draft_update(): void
    {
        $tool = $this->draft();
        $revision = $tool->current_revision_id;
        try {
            app(ToolLifecycle::class)->saveDraft($tool, ['name' => 'Invalid threshold', 'definition' => $this->definition(), 'failure_threshold' => 0], $this->context);
            $this->fail('An invalid failure threshold was accepted.');
        } catch (ValidationException) {
            $this->assertSame($revision, $tool->fresh()->current_revision_id);
            $this->assertSame('Order report', $tool->fresh()->name);
            $this->assertDatabaseCount('api_tool_revisions', 1);
        }
    }

    public function test_sweep_recovers_terminal_and_expired_runs_and_restores_worker_scope(): void
    {
        config()->set('api-tools.enabled', true);
        Bus::fake([VerifyApiTool::class, SweepApiTools::class]);
        $tool = $this->draft();
        $tool->update(['status' => 'active', 'publication_intent' => true]);
        $terminal = $this->fixtureRun($tool, 'failed');
        $expired = $this->fixtureRun($tool, 'running');
        $expired->update(['completed_at' => null, 'deadline_at' => now()->subMinute()]);
        app(TenantContext::class)->set('other-tenant');
        app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set('other-tenant');
        (new SweepApiTools)->handle(app(ToolLifecycle::class), app(ToolVerificationService::class));
        $this->assertNotNull($terminal->fresh()->resumed_at);
        $this->assertSame('timed_out', $expired->fresh()->status);
        $this->assertSame(2, $tool->fresh()->consecutive_failures);
        $this->assertSame('other-tenant', app(TenantContext::class)->current());
        $this->assertSame('other-tenant', app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->current());
        $this->context->activate();
    }

    public function test_verification_unique_lock_tracks_the_snapshot_across_a_b_c_a_while_older_work_is_pending(): void
    {
        config()->set('api-tools.enabled', true);
        config()->set('connector-api.ssrf.resolve_dns', false);
        Bus::fake([VerifyApiTool::class, SweepApiTools::class, \Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob::class]);
        Http::fake(['api.example.com/*' => Http::response(['items' => [1]])]);
        $tool = $this->draft();
        $tool->update(['publication_intent' => true, 'status' => 'deprecated']);
        VerifyApiTool::dispatch($tool->id, $this->context->jsonSerialize(), 'full', true);
        $first = Bus::dispatched(VerifyApiTool::class)->first();
        $firstKey = $first->uniqueId();
        $locks = new UniqueLock(app(\Illuminate\Contracts\Cache\Repository::class));
        $this->assertFalse($locks->acquire(unserialize(serialize($first))));
        $first->handle(app(ToolVerificationService::class));
        $oldLiveRun = ApiToolRun::query()->where('status', 'running')->firstOrFail();
        $oldVerification = ApiToolVerification::query()->where('mode', 'live')->firstOrFail();

        $this->route->update(['url' => 'https://api.example.com/b/orders']);
        app(ToolLifecycle::class)->checkDependencies($tool->fresh(), $this->context);
        $hashB = $tool->fresh()->content_hash;
        $oldLiveRun->update(['status' => 'completed', 'physical_requests' => 1, 'outputs' => ['items' => [1]], 'completed_at' => now()]);
        app(ToolVerificationService::class)->consumeRun($oldLiveRun->fresh());
        $this->assertSame('stale', $oldVerification->fresh()->status);
        $this->assertSame('deprecated', $tool->fresh()->status);
        $this->assertSame($hashB, $tool->fresh()->content_hash);

        foreach (['https://api.example.com/c/orders', 'https://api.example.com/orders'] as $url) {
            $this->route->update(['url' => $url]);
            app(ToolLifecycle::class)->checkDependencies($tool->fresh(), $this->context);
        }
        $jobs = Bus::dispatched(VerifyApiTool::class);
        $latest = $jobs->last();
        Bus::assertDispatchedTimes(VerifyApiTool::class, 4);
        $this->assertSame($first->contentHash, $latest->contentHash);
        $this->assertGreaterThan($first->generation, $latest->generation);
        $this->assertCount(4, $jobs->map(fn ($job) => $job->uniqueId())->unique());
        $this->assertSame($firstKey, $first->uniqueId());
        $this->assertNotSame($firstKey, $latest->uniqueId());

        // An obsolete queued delivery cannot start another suite or consume the latest key.
        $first->handle(app(ToolVerificationService::class));
        Bus::assertDispatchedTimes(VerifyApiTool::class, 4);
        $this->assertDatabaseCount('api_tool_verifications', 3);
        $locks->release($first);
        $latestKey = $latest->uniqueId();
        $this->assertFalse($locks->acquire(unserialize(serialize($latest))));

        $latest->handle(app(ToolVerificationService::class));
        $this->assertSame($latestKey, $latest->uniqueId());
        $latestRun = ApiToolRun::query()->where('status', 'running')->firstOrFail();
        $runtime = app(\App\ApiTools\ToolRuntime::class);
        $runtime->executeStep($latestRun->id, 'orders');
        $runtime->executeStep($latestRun->id, 'tool_output');
        $runtime->finish($latestRun->id, 'completed');
        $this->assertSame(1, $latestRun->fresh()->physical_requests);
        $this->assertTrue($tool->fresh()->available());
        $this->assertSame('passed', ApiToolVerification::query()->where('mode', 'live')->latest('id')->firstOrFail()->status);
        $locks->release($latest);
        $this->assertTrue($locks->acquire(unserialize(serialize($latest))));
    }

    public function test_obsolete_job_repairs_a_missing_latest_enqueue_but_manual_disable_stops_it(): void
    {
        config()->set('api-tools.enabled', true);
        Bus::fake([VerifyApiTool::class, SweepApiTools::class]);
        $tool = $this->draft();
        $tool->update(['publication_intent' => true, 'status' => 'deprecated']);
        $obsolete = new VerifyApiTool($tool->id, $this->context->jsonSerialize(), 'full', true);
        $this->route->update(['url' => 'https://api.example.com/latest/orders']);
        // Model an interrupted mutation after the new snapshot committed but before enqueue.
        $tool->update([
            'verification_generation' => $tool->verification_generation + 1,
            'content_hash' => app(ContentHasher::class)->tool($tool->revision->definition, $tool->tenant_id, $tool->project_key),
        ]);
        $obsolete->handle(app(ToolVerificationService::class));
        Bus::assertDispatchedTimes(VerifyApiTool::class, 1);
        $latest = Bus::dispatched(VerifyApiTool::class)->first();
        $this->assertSame($tool->content_hash, $latest->contentHash);
        $this->assertSame($tool->verification_generation, $latest->generation);
        $this->assertDatabaseCount('api_tool_verifications', 0);
        app(ToolLifecycle::class)->disable($tool, $this->context);
        $obsolete->handle(app(ToolVerificationService::class));
        Bus::assertDispatchedTimes(VerifyApiTool::class, 1);
        $this->assertSame('disabled_manual', $tool->fresh()->status);
    }

    private function draft(): ApiTool
    {
        return app(ToolLifecycle::class)->saveDraft(null, ['name' => 'Order report', 'definition' => $this->definition()], $this->context);
    }

    private function definition(): array
    {
        return ['version' => 1, 'input_schema' => ['type' => 'object'],
            'steps' => [['id' => 'orders', 'operation' => 'api', 'route_id' => $this->route->id, 'bindings' => []]],
            'output' => ['items' => ['source' => 'step', 'step' => 'orders', 'path' => 'items']],
            'tests' => [
                ['name' => 'fixture', 'mode' => 'simulation', 'inputs' => [], 'fixtures' => ['orders' => ['items' => [1]]], 'assertions' => [['path' => 'items', 'operator' => 'count', 'value' => 1]]],
                ['name' => 'live', 'mode' => 'live', 'inputs' => [], 'assertions' => [['path' => 'items', 'operator' => 'count', 'value' => 1]]],
            ],
        ];
    }

    private function fixtureRun(ApiTool $tool, string $status, ?string $error = null, string $mode = 'production'): ApiToolRun
    {
        return ApiToolRun::query()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $tool->tenant_id, 'project_key' => $tool->project_key,
            'api_tool_id' => $tool->id, 'api_tool_revision_id' => $tool->current_revision_id, 'content_hash' => $tool->content_hash,
            'status' => $status, 'mode' => $mode, 'error_code' => $error, 'snapshot' => $tool->revision->definition,
            'context' => $this->context->jsonSerialize(), 'inputs' => [], 'outputs' => ['items' => [1]], 'continuation' => [],
            'physical_requests' => 1, 'request_limit' => 10, 'deadline_at' => now()->addMinute(), 'completed_at' => now(),
        ]);
    }

    private function liveVerification(ApiTool $tool): array
    {
        $service = app(ToolVerificationService::class);
        $verification = ApiToolVerification::query()->create([
            'tenant_id' => $tool->tenant_id, 'api_tool_id' => $tool->id, 'api_tool_revision_id' => $tool->current_revision_id,
            'content_hash' => $tool->content_hash, 'scope_hash' => $service->scopeHash($tool, $this->context),
            'tests_hash' => app(ContentHasher::class)->hash($tool->revision->definition['tests']),
            'generation' => $tool->verification_generation, 'mode' => 'live', 'status' => 'running', 'created_at' => now(),
        ]);
        $run = $this->fixtureRun($tool, 'completed', null, 'verification');
        $run->update(['continuation' => ['type' => 'verification', 'verification_id' => $verification->id, 'case_index' => 0]]);
        $verification->update(['results' => ['context' => $this->context->jsonSerialize(), 'definitions' => [$this->definition()['tests'][1]], 'cases' => [], 'active_run_id' => $run->id, 'auto' => true]]);
        return [$verification, $run->fresh()];
    }
}
