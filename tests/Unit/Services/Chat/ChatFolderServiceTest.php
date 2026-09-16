<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chat;

use App\Models\ChatFolder;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ChatFolderService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The R44 PHP surface for chat folders, tested at the SERVICE — not
 * through HTTP. Folders are private per (tenant, user) with no policy
 * and no global scope, so the scoping this class applies IS the
 * authorization; a controller test would prove nothing about a CLI or
 * queue caller.
 */
final class ChatFolderServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChatFolderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatFolderService();
    }

    /** This repo has no model factories; tests build users directly. */
    private function user(): User
    {
        return User::create([
            'name' => 'Folder Owner',
            'email' => 'folders-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_it_lists_only_the_owners_folders_in_the_active_tenant(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $mine = $this->service->create($owner->id, 'acme', 'Issue 42');
        $this->service->create($other->id, 'acme', 'Someone else');
        $this->service->create($owner->id, 'globex', 'Other tenant');

        $listed = $this->service->listFor($owner->id, 'acme');

        $this->assertSame([$mine->id], $listed->pluck('id')->all());
    }

    public function test_it_orders_by_position_then_name(): void
    {
        $owner = $this->user();
        // Fixture must FAIL under name-only ordering: 'Zeta' has the lower
        // position, so a correct sort puts it before 'Alpha'.
        $this->service->create($owner->id, 'acme', 'Alpha', 5);
        $this->service->create($owner->id, 'acme', 'Zeta', 1);
        $this->service->create($owner->id, 'acme', 'Beta', 5);

        $names = $this->service->listFor($owner->id, 'acme')->pluck('name')->all();

        $this->assertSame(['Zeta', 'Alpha', 'Beta'], $names);
    }

    public function test_it_fills_the_tenant_from_the_argument_not_the_ambient_context(): void
    {
        // BelongsToTenant auto-fills from TenantContext when tenant_id is
        // empty; passing it explicitly must win, so a queue/CLI caller can
        // act for a tenant that is not the ambient one.
        app(TenantContext::class)->set('acme');
        $owner = $this->user();

        $folder = $this->service->create($owner->id, 'globex', 'Elsewhere');

        $this->assertSame('globex', $folder->tenant_id);
    }

    public function test_find_returns_null_for_another_users_folder_rather_than_leaking_its_existence(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $theirs = $this->service->create($other->id, 'acme', 'Private');

        $this->assertNull($this->service->find($theirs->id, $owner->id, 'acme'));
    }

    public function test_find_returns_null_across_tenants(): void
    {
        $owner = $this->user();
        $elsewhere = $this->service->create($owner->id, 'globex', 'Other tenant');

        $this->assertNull($this->service->find($elsewhere->id, $owner->id, 'acme'));
        $this->assertNotNull($this->service->find($elsewhere->id, $owner->id, 'globex'));
    }

    public function test_the_same_name_is_reusable_across_users_and_tenants(): void
    {
        // R30/R31: the composite unique is (tenant_id, user_id, name), so
        // two tenants — and two users in one tenant — may each have an
        // "Issue 42". Global uniqueness would block the obvious name.
        $a = $this->user();
        $b = $this->user();

        $this->service->create($a->id, 'acme', 'Issue 42');
        $this->service->create($b->id, 'acme', 'Issue 42');
        $this->service->create($a->id, 'globex', 'Issue 42');

        $this->assertSame(3, ChatFolder::query()->where('name', 'Issue 42')->count());
    }

    public function test_a_duplicate_name_raises_a_validation_error_not_a_driver_error(): void
    {
        // The service is a public PHP surface (R44) and validate-then-insert
        // is not atomic, so the DB unique is the real invariant. This proves
        // the constraint surfaces as a 422-shaped failure rather than a 500.
        $owner = $this->user();
        $this->service->create($owner->id, 'acme', 'Issue 42');

        $this->expectException(ValidationException::class);
        $this->service->create($owner->id, 'acme', 'Issue 42');
    }

    public function test_renaming_onto_a_taken_name_raises_a_validation_error(): void
    {
        $owner = $this->user();
        $this->service->create($owner->id, 'acme', 'Taken');
        $folder = $this->service->create($owner->id, 'acme', 'Mine');

        $this->expectException(ValidationException::class);
        $this->service->rename($folder, 'Taken');
    }

    public function test_rename_persists_the_new_name(): void
    {
        $owner = $this->user();
        $folder = $this->service->create($owner->id, 'acme', 'Old');

        $renamed = $this->service->rename($folder, 'New');

        $this->assertSame('New', $renamed->name);
        $this->assertDatabaseHas('chat_folders', ['id' => $folder->id, 'name' => 'New']);
    }

    public function test_deleting_a_folder_unfiles_its_conversations_and_deletes_none_of_them(): void
    {
        $owner = $this->user();
        $folder = $this->service->create($owner->id, 'acme', 'Issue 42');
        $conversation = Conversation::query()->create([
            'tenant_id' => 'acme',
            'user_id' => $owner->id,
            'title' => 'Keep me',
            'project_key' => 'engineering',
            'chat_folder_id' => $folder->id,
        ]);

        $this->service->delete($folder);

        // Cascading here would silently destroy chat history, so the FK is
        // nullOnDelete and the thread survives, merely unfiled.
        $this->assertDatabaseMissing('chat_folders', ['id' => $folder->id]);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'chat_folder_id' => null,
        ]);
        $this->assertSame(1, Conversation::query()->count());
    }
}
