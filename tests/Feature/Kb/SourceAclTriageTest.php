<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\UnmappedSourcePrincipal;
use App\Models\User;
use App\Services\Kb\Access\SourceAclMirror;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;
use Tests\TestCase;

/**
 * ADR 0028 phase 2 — the queue of principals a source named and this
 * application could not place, across all three surfaces (R44).
 *
 * The queue exists because the alternative is silence. Dropping an
 * unresolved principal narrows the mirrored list below what the source
 * actually said, so somebody with legitimate upstream access quietly loses
 * it here — and nobody finds out, because a missing grant looks exactly like
 * a share that was never made.
 */
final class SourceAclTriageTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private KnowledgeDocument $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        $this->document = $this->makeDocument('drive/board/notes.md');
    }

    public function test_a_principal_that_cannot_be_placed_becomes_a_question(): void
    {
        $this->mirror([
            SourcePrincipal::user('contractor@agency.example'),
            SourcePrincipal::group('board-members'),
            SourcePrincipal::domain('example.com'),
        ]);

        $rows = UnmappedSourcePrincipal::query()->get();

        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            ['contractor@agency.example', 'board-members', 'example.com'],
            $rows->pluck('principal_external_id')->all(),
        );
        $this->assertTrue($rows->every(
            fn (UnmappedSourcePrincipal $r): bool => $r->status === UnmappedSourcePrincipal::STATUS_PENDING,
        ));
    }

    public function test_a_resolved_principal_never_reaches_the_queue(): void
    {
        $this->member('colleague@example.com');

        $this->mirror([SourcePrincipal::user('colleague@example.com')]);

        $this->assertSame(0, UnmappedSourcePrincipal::query()->count());
    }

    public function test_a_dismissed_principal_is_not_asked_about_again(): void
    {
        // Without this, an operator dismisses the same external collaborator
        // on every sync and the queue becomes noise nobody reads.
        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);

        $row = UnmappedSourcePrincipal::query()->firstOrFail();
        $row->update(['status' => UnmappedSourcePrincipal::STATUS_IGNORED]);
        $firstSeen = $row->first_seen_at;

        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);

        $row->refresh();

        $this->assertSame(UnmappedSourcePrincipal::STATUS_IGNORED, $row->status);
        $this->assertEquals(
            $firstSeen?->toDateTimeString(),
            $row->first_seen_at?->toDateTimeString(),
            'first_seen_at moved, so the queue can no longer show how long a question has gone unanswered.',
        );
    }

    public function test_a_principal_the_source_stops_naming_stops_being_a_question(): void
    {
        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);
        $this->assertSame(1, UnmappedSourcePrincipal::query()->count());

        $this->mirror([SourcePrincipal::user('someone-else@agency.example')]);

        $this->assertSame(
            ['someone-else@agency.example'],
            UnmappedSourcePrincipal::query()->pluck('principal_external_id')->all(),
        );
    }

    public function test_the_command_lists_the_queue_and_records_a_decision(): void
    {
        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);

        $this->artisan('kb:source-acl')
            ->expectsOutputToContain('contractor@agency.example')
            ->assertSuccessful();

        $id = UnmappedSourcePrincipal::query()->value('id');

        $this->artisan('kb:source-acl', ['--ignore' => $id])
            ->assertSuccessful();

        $this->assertSame(
            UnmappedSourcePrincipal::STATUS_IGNORED,
            UnmappedSourcePrincipal::query()->value('status'),
        );
    }

    public function test_the_command_fails_loudly_on_an_unknown_entry(): void
    {
        // R14 — a missing id must not read as "decision recorded".
        $this->artisan('kb:source-acl', ['--ignore' => 4242])
            ->assertFailed();
    }

    public function test_the_api_lists_the_queue_and_records_a_decision(): void
    {
        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/api/admin/kb/source-acl')
            ->assertOk()
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.documents_restricted', 1)
            ->assertJsonPath('data.0.principal', 'contractor@agency.example');

        $id = UnmappedSourcePrincipal::query()->value('id');

        $this->actingAs($admin)
            ->patchJson('/api/admin/kb/source-acl/'.$id, ['status' => 'ignored'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ignored');
    }

    public function test_the_api_rejects_an_unknown_status(): void
    {
        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);
        $id = UnmappedSourcePrincipal::query()->value('id');

        $this->actingAs($this->adminUser())
            ->patchJson('/api/admin/kb/source-acl/'.$id, ['status' => 'granted'])
            ->assertStatus(422);
    }

    public function test_an_entry_from_another_tenant_is_not_found(): void
    {
        // R30 — 404 rather than 403, so the response does not confirm that
        // the id exists.
        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);
        $id = UnmappedSourcePrincipal::query()->value('id');

        UnmappedSourcePrincipal::query()->whereKey($id)->update(['tenant_id' => 'other-tenant']);

        $this->actingAs($this->adminUser())
            ->patchJson('/api/admin/kb/source-acl/'.$id, ['status' => 'ignored'])
            ->assertStatus(404);
    }

    public function test_mirroring_does_not_make_the_document_look_edited(): void
    {
        // `updated_at` is what a staleness report, a digest or a reviewer
        // reads as "the content changed". A permission sync changes how the
        // document is GOVERNED, not what it says, and bumping the timestamp
        // on every sync would make every synced document permanently look
        // freshly edited.
        $before = $this->document->fresh()->updated_at;

        $this->travelTo(now()->addHour());
        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);
        $this->travelBack();

        $this->assertEquals(
            $before?->toDateTimeString(),
            $this->document->fresh()->updated_at?->toDateTimeString(),
            'The permission sync bumped updated_at, so the document now reads as edited.',
        );
        $this->assertNotNull(
            $this->document->fresh()->source_acl_enforced_at,
            'Precondition: the sync did happen.',
        );
    }

    public function test_the_queue_never_exposes_another_tenants_document(): void
    {
        // R30 defence in depth. BelongsToTenant adds no global READ scope, so
        // a row whose document id pointed elsewhere -- corruption, or a
        // crafted write -- would otherwise load that document's title and
        // path straight into the response.
        $this->mirror([SourcePrincipal::user('contractor@agency.example')]);

        $foreign = $this->makeDocument('other/tenant/secret-roadmap.md');
        $foreign->forceFill(['tenant_id' => 'other-tenant'])->save();

        UnmappedSourcePrincipal::query()->update(['knowledge_document_id' => $foreign->id]);

        $this->actingAs($this->adminUser())
            ->getJson('/api/admin/kb/source-acl')
            ->assertOk()
            ->assertJsonPath('data.0.document_title', null)
            ->assertJsonPath('data.0.source_path', null);
    }

    /**
     * @param  list<SourcePrincipal>  $principals
     */
    private function mirror(array $principals): void
    {
        app(SourceAclMirror::class)->syncFor(
            $this->document,
            SourceAccess::of($principals),
        );
    }

    private function member(string $email): User
    {
        $user = User::create([
            'name' => 'Colleague',
            'email' => $email,
            'password' => bcrypt('secret-secret'),
        ]);

        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => 'default',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        return $user->fresh();
    }

    private function adminUser(): User
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = $this->member('admin@example.com');
        $user->assignRole('admin');

        return $user->fresh();
    }

    private function makeDocument(string $sourcePath): KnowledgeDocument
    {
        return KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'source_type' => 'connector',
            'title' => basename($sourcePath),
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'indexed',
            'document_hash' => hash('sha256', $sourcePath),
            'version_hash' => hash('sha256', $sourcePath.'v1'),
        ]);
    }
}
