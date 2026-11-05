<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ExecuteKbWikiExportJob;
use App\Models\KbWikiExportRequest;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v8.38/W4b (ADR 0032 §5) — principal-restoration discipline for the async
 * export job, mirroring `ExecuteAgentRunJob`'s own tests: no user id on the
 * request → refuses; a user without the export permission → refuses; a
 * reused worker never leaks one job's principal into the next.
 */
final class ExecuteKbWikiExportJobTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $projectKey = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        $this->seed(RbacSeeder::class);
        Storage::fake((string) config('kb.staging.disk', 'kb-staging'));
    }

    private function makeUser(array $roles = ['admin']): User
    {
        $user = User::create([
            'name' => 'Exporter',
            'email' => 'exporter-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function document(string $path): KnowledgeDocument
    {
        $hash = hash('sha256', $path);

        return KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => $this->projectKey,
            'source_type' => 'markdown',
            'title' => basename($path),
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'active',
            'document_hash' => $hash,
            'version_hash' => $hash,
        ]);
    }

    private function requestRow(): KbWikiExportRequest
    {
        return KbWikiExportRequest::create([
            'tenant_id' => $this->tenantId,
            'project_key' => $this->projectKey,
            'status' => KbWikiExportRequest::STATUS_QUEUED,
            'options_json' => ['format' => 'llm-wiki', 'include_images' => false],
            'idempotency_key' => Str::random(64),
        ]);
    }

    public function test_a_successful_run_completes_the_request_and_stages_a_zip(): void
    {
        $this->document('docs/a.md');
        $this->document('docs/b.md');
        $user = $this->makeUser();
        $request = $this->requestRow();

        (new ExecuteKbWikiExportJob($request->id, (int) $user->id, $this->tenantId))->handle(
            app(\App\Services\Kb\Export\KbWikiExportService::class),
            app(TenantContext::class),
        );

        $request->refresh();
        $this->assertSame(KbWikiExportRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(2, $request->document_count);
        $this->assertNotNull($request->storage_path);
        $this->assertNotNull($request->expires_at);
        $this->assertCount(2, $request->document_ids_json);
        Storage::disk((string) config('kb.staging.disk'))->assertExists($request->storage_path);
    }

    public function test_the_guard_is_cleared_after_the_job_runs(): void
    {
        $this->document('docs/a.md');
        $user = $this->makeUser();
        $request = $this->requestRow();

        (new ExecuteKbWikiExportJob($request->id, (int) $user->id, $this->tenantId))->handle(
            app(\App\Services\Kb\Export\KbWikiExportService::class),
            app(TenantContext::class),
        );

        $this->assertNull(Auth::user());
    }

    public function test_a_user_who_no_longer_holds_export_permission_fails_the_request_without_running_export(): void
    {
        $this->document('docs/a.md');
        $user = $this->makeUser(roles: []); // no admin/super-admin role
        $request = $this->requestRow();

        (new ExecuteKbWikiExportJob($request->id, (int) $user->id, $this->tenantId))->handle(
            app(\App\Services\Kb\Export\KbWikiExportService::class),
            app(TenantContext::class),
        );

        $request->refresh();
        $this->assertSame(KbWikiExportRequest::STATUS_FAILED, $request->status);
        $this->assertNotNull($request->error_message);
        $this->assertNull($request->storage_path);
    }

    public function test_a_deleted_user_fails_the_request(): void
    {
        $this->document('docs/a.md');
        $request = $this->requestRow();

        (new ExecuteKbWikiExportJob($request->id, 999999999, $this->tenantId))->handle(
            app(\App\Services\Kb\Export\KbWikiExportService::class),
            app(TenantContext::class),
        );

        $request->refresh();
        $this->assertSame(KbWikiExportRequest::STATUS_FAILED, $request->status);
    }

    /**
     * "Queue workers are long-lived. Never leak one run's principal into
     * the next job" — the exact invariant `ExecuteAgentRunJob` documents
     * and this job mirrors. Runs job A (as user A) then job B (as user B)
     * back to back in the SAME process and asserts B's export only ever
     * saw user B as the active guard, never a leftover from A.
     */
    public function test_worker_reuse_never_leaks_one_jobs_principal_into_the_next(): void
    {
        $this->document('docs/a.md');
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $requestA = $this->requestRow();
        $requestB = $this->requestRow();

        $service = app(\App\Services\Kb\Export\KbWikiExportService::class);
        $tenants = app(TenantContext::class);

        (new ExecuteKbWikiExportJob($requestA->id, (int) $userA->id, $this->tenantId))->handle($service, $tenants);
        $this->assertNull(Auth::user(), 'Guard must be cleared after job A before job B starts.');

        (new ExecuteKbWikiExportJob($requestB->id, (int) $userB->id, $this->tenantId))->handle($service, $tenants);

        $requestA->refresh();
        $requestB->refresh();
        $this->assertSame(KbWikiExportRequest::STATUS_COMPLETED, $requestA->status);
        $this->assertSame(KbWikiExportRequest::STATUS_COMPLETED, $requestB->status);
        $this->assertNull(Auth::user());
    }
}
