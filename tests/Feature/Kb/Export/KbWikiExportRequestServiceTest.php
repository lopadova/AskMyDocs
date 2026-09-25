<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Export;

use App\Jobs\ExecuteKbWikiExportJob;
use App\Models\KbWikiExportRequest;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Export\KbWikiExportRequestService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v8.38/W4b (ADR 0032 §11) — the async front door's idempotency contract.
 *
 * Every fixture authenticates as the requesting user BEFORE calling the
 * service, matching the controller's own call shape (`requestExport()`
 * asserts `Auth::user()` is already `$requestingUser` — it never swaps the
 * guard itself, see the service's own doc comment).
 */
final class KbWikiExportRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $projectKey = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        Queue::fake();
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Exporter',
            'email' => 'exporter-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();
    }

    private function membership(User $user): void
    {
        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => $this->projectKey,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);
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

    public function test_repeat_request_with_identical_state_reuses_the_same_row(): void
    {
        $this->document('docs/a.md');
        $user = $this->makeUser();
        $this->membership($user);
        Auth::login($user);

        $service = app(KbWikiExportRequestService::class);
        $first = $service->requestExport($this->tenantId, $this->projectKey, $user, []);
        $second = $service->requestExport($this->tenantId, $this->projectKey, $user, []);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KbWikiExportRequest::query()->forTenant($this->tenantId)->count());
        Queue::assertPushed(ExecuteKbWikiExportJob::class, 1);
    }

    public function test_a_new_document_in_the_corpus_starts_a_fresh_export(): void
    {
        $this->document('docs/a.md');
        $user = $this->makeUser();
        $this->membership($user);
        Auth::login($user);

        $service = app(KbWikiExportRequestService::class);
        $first = $service->requestExport($this->tenantId, $this->projectKey, $user, []);

        $this->document('docs/b.md');
        $second = $service->requestExport($this->tenantId, $this->projectKey, $user, []);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, KbWikiExportRequest::query()->forTenant($this->tenantId)->count());
    }

    /**
     * ADR 0032 §11 — the authorization digest, not the corpus snapshot,
     * is what makes a NARROWED view invalidate the cache: removing the
     * user's membership (so `AccessScopeScope` now hides every document)
     * doesn't move any document's `updated_at`, so a digest-less key would
     * incorrectly reuse the first export.
     */
    public function test_a_narrowed_acl_view_starts_a_fresh_export_even_though_the_corpus_is_unchanged(): void
    {
        $this->document('docs/a.md');
        $user = $this->makeUser();
        $this->membership($user);
        Auth::login($user);

        $service = app(KbWikiExportRequestService::class);
        $first = $service->requestExport($this->tenantId, $this->projectKey, $user, []);

        ProjectMembership::query()
            ->forTenant($this->tenantId)
            ->where('user_id', $user->id)
            ->delete();

        $second = $service->requestExport($this->tenantId, $this->projectKey, $user, []);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_requesting_on_behalf_of_a_different_user_than_the_active_guard_refuses(): void
    {
        $requestingUser = $this->makeUser();
        $activeUser = $this->makeUser();
        $this->membership($requestingUser);
        Auth::login($activeUser);

        $this->expectException(\RuntimeException::class);

        app(KbWikiExportRequestService::class)->requestExport($this->tenantId, $this->projectKey, $requestingUser, []);
    }

    public function test_an_unsupported_format_option_is_rejected_not_silently_ignored(): void
    {
        $user = $this->makeUser();
        $this->membership($user);
        Auth::login($user);

        $this->expectException(\InvalidArgumentException::class);

        app(KbWikiExportRequestService::class)->requestExport(
            $this->tenantId,
            $this->projectKey,
            $user,
            ['format' => 'markdown'],
        );
    }

    public function test_include_images_true_is_rejected_not_silently_ignored(): void
    {
        $user = $this->makeUser();
        $this->membership($user);
        Auth::login($user);

        $this->expectException(\InvalidArgumentException::class);

        app(KbWikiExportRequestService::class)->requestExport(
            $this->tenantId,
            $this->projectKey,
            $user,
            ['include_images' => true],
        );
    }
}
