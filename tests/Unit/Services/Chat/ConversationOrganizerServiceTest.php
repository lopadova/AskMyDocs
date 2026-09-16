<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chat;

use App\Exceptions\Chat\ChatFolderNotOwnedException;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ChatFolderService;
use App\Services\Chat\ConversationOrganizerService;
use App\Support\Chat\ConversationArchiveScope;
use App\Support\Chat\ConversationImportance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The R44 PHP surface for session organisation, tested at the SERVICE.
 * Tenant + owner scoping lives here rather than in the controller, so
 * this is where it must be proved — an HTTP test would say nothing about
 * a CLI or queue caller.
 */
final class ConversationOrganizerServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationOrganizerService $service;

    private ChatFolderService $folders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folders = new ChatFolderService();
        $this->service = new ConversationOrganizerService($this->folders);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Session Owner',
            'email' => 'sessions-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function conversation(User $user, array $attributes = [], string $tenantId = 'acme'): Conversation
    {
        return Conversation::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'title' => 'Thread',
            'project_key' => 'engineering',
        ], $attributes));
    }

    public function test_it_lists_only_the_owners_sessions_in_the_active_tenant(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $mine = $this->conversation($owner, ['title' => 'Mine']);
        $this->conversation($other, ['title' => 'Theirs']);
        $this->conversation($owner, ['title' => 'Elsewhere'], 'globex');

        $listed = $this->service->list($owner->id, 'acme', ConversationArchiveScope::Active);

        $this->assertSame([$mine->id], $listed->pluck('id')->all());
    }

    public function test_archived_sessions_are_excluded_by_default_and_reachable_on_request(): void
    {
        $owner = $this->user();
        $active = $this->conversation($owner, ['title' => 'Active']);
        $archived = $this->conversation($owner, [
            'title' => 'Archived',
            'archived_at' => now(),
        ]);

        $this->assertSame(
            [$active->id],
            $this->service->list($owner->id, 'acme', ConversationArchiveScope::Active)->pluck('id')->all(),
        );
        $this->assertSame(
            [$archived->id],
            $this->service->list($owner->id, 'acme', ConversationArchiveScope::Archived)->pluck('id')->all(),
        );
        $this->assertCount(
            2,
            $this->service->list($owner->id, 'acme', ConversationArchiveScope::All),
        );
    }

    public function test_it_orders_pinned_first_then_importance_then_recency(): void
    {
        $owner = $this->user();

        // The fixture is built to FAIL under plain `updated_at DESC`: the
        // most recently updated thread is the one that must sort LAST.
        $newestButNormal = $this->conversation($owner, ['title' => 'newest-normal']);
        $newestButNormal->forceFill(['updated_at' => Carbon::parse('2026-09-16 12:00:00')])->saveQuietly();

        $critical = $this->conversation($owner, [
            'title' => 'critical',
            'importance' => ConversationImportance::Critical->value,
        ]);
        $critical->forceFill(['updated_at' => Carbon::parse('2026-09-10 12:00:00')])->saveQuietly();

        $high = $this->conversation($owner, [
            'title' => 'high',
            'importance' => ConversationImportance::High->value,
        ]);
        $high->forceFill(['updated_at' => Carbon::parse('2026-09-11 12:00:00')])->saveQuietly();

        $pinnedOld = $this->conversation($owner, [
            'title' => 'pinned',
            'pinned_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        $pinnedOld->forceFill(['updated_at' => Carbon::parse('2026-01-01 00:00:00')])->saveQuietly();

        $titles = $this->service
            ->list($owner->id, 'acme', ConversationArchiveScope::Active)
            ->pluck('title')
            ->all();

        $this->assertSame(['pinned', 'critical', 'high', 'newest-normal'], $titles);
    }

    public function test_more_recently_pinned_sessions_sort_above_older_pins(): void
    {
        $owner = $this->user();
        $older = $this->conversation($owner, [
            'title' => 'pinned-older',
            'pinned_at' => Carbon::parse('2026-05-01 00:00:00'),
        ]);
        $newer = $this->conversation($owner, [
            'title' => 'pinned-newer',
            'pinned_at' => Carbon::parse('2026-06-01 00:00:00'),
        ]);

        $titles = $this->service
            ->list($owner->id, 'acme', ConversationArchiveScope::Active)
            ->pluck('title')
            ->all();

        $this->assertSame(['pinned-newer', 'pinned-older'], $titles);
        $this->assertNotNull($older->pinned_at);
        $this->assertNotNull($newer->pinned_at);
    }

    public function test_pinning_does_not_bump_updated_at(): void
    {
        // updated_at is BOTH the recency ordering key and the "last
        // activity" the sidebar shows. If organisation bumped it,
        // archive-then-restore would teleport an untouched thread to the
        // top of the user's list.
        $owner = $this->user();
        $conversation = $this->conversation($owner);
        $conversation->forceFill(['updated_at' => Carbon::parse('2026-01-01 00:00:00')])->saveQuietly();
        $before = $conversation->fresh()->updated_at;

        Carbon::setTestNow('2026-09-16 15:00:00');
        $this->service->setPinned($conversation, true);
        Carbon::setTestNow();

        $after = $conversation->fresh();
        $this->assertTrue($before->equalTo($after->updated_at), 'updated_at must not move on a pin.');
        $this->assertNotNull($after->pinned_at);
    }

    public function test_archiving_and_importance_do_not_bump_updated_at_either(): void
    {
        $owner = $this->user();
        $conversation = $this->conversation($owner);
        $conversation->forceFill(['updated_at' => Carbon::parse('2026-01-01 00:00:00')])->saveQuietly();
        $before = $conversation->fresh()->updated_at;

        $this->service->setArchived($conversation, true);
        $this->service->setImportance($conversation, ConversationImportance::Critical);

        $after = $conversation->fresh();
        $this->assertTrue($before->equalTo($after->updated_at));
        $this->assertNotNull($after->archived_at);
        $this->assertSame(ConversationImportance::Critical, $after->importance);
    }

    public function test_it_restores_timestamps_so_a_later_write_still_records_activity(): void
    {
        // The organiser disables timestamps on the instance; leaving them
        // off would make a rename in the same request go unrecorded.
        $owner = $this->user();
        $conversation = $this->conversation($owner);
        $conversation->forceFill(['updated_at' => Carbon::parse('2026-01-01 00:00:00')])->saveQuietly();

        $this->service->setPinned($conversation, true);

        Carbon::setTestNow('2026-09-16 15:00:00');
        $conversation->update(['title' => 'Renamed']);
        Carbon::setTestNow();

        $this->assertSame(
            '2026-09-16 15:00:00',
            $conversation->fresh()->updated_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_unpinning_and_unarchiving_clear_the_timestamps(): void
    {
        $owner = $this->user();
        $conversation = $this->conversation($owner, [
            'pinned_at' => now(),
            'archived_at' => now(),
        ]);

        $this->service->setPinned($conversation, false);
        $this->service->setArchived($conversation, false);

        $fresh = $conversation->fresh();
        $this->assertNull($fresh->pinned_at);
        $this->assertNull($fresh->archived_at);
    }

    public function test_it_files_a_session_into_a_folder_the_user_owns(): void
    {
        $owner = $this->user();
        $folder = $this->folders->create($owner->id, 'acme', 'Issue 42');
        $conversation = $this->conversation($owner);

        $this->service->setFolder($conversation, $folder->id);

        $this->assertSame($folder->id, $conversation->fresh()->chat_folder_id);
    }

    public function test_it_unfiles_a_session_with_a_null_folder(): void
    {
        $owner = $this->user();
        $folder = $this->folders->create($owner->id, 'acme', 'Issue 42');
        $conversation = $this->conversation($owner, ['chat_folder_id' => $folder->id]);

        $this->service->setFolder($conversation, null);

        $this->assertNull($conversation->fresh()->chat_folder_id);
    }

    public function test_it_refuses_another_users_folder_instead_of_cross_filing(): void
    {
        // R21 / SEC-IDOR-001: the FormRequest validates ownership too, but
        // this service is a public PHP surface — an unowned id must fail
        // loudly, never move a thread into someone else's folder.
        $owner = $this->user();
        $other = $this->user();
        $theirFolder = $this->folders->create($other->id, 'acme', 'Private');
        $conversation = $this->conversation($owner);

        $this->expectException(ChatFolderNotOwnedException::class);

        try {
            $this->service->setFolder($conversation, $theirFolder->id);
        } finally {
            $this->assertNull($conversation->fresh()->chat_folder_id);
        }
    }

    public function test_it_refuses_a_folder_from_another_tenant(): void
    {
        $owner = $this->user();
        $elsewhere = $this->folders->create($owner->id, 'globex', 'Other tenant');
        $conversation = $this->conversation($owner);

        $this->expectException(ChatFolderNotOwnedException::class);
        $this->service->setFolder($conversation, $elsewhere->id);
    }

    public function test_it_refuses_a_folder_that_does_not_exist(): void
    {
        $owner = $this->user();
        $conversation = $this->conversation($owner);

        $this->expectException(ChatFolderNotOwnedException::class);
        $this->service->setFolder($conversation, 99_999);
    }
}
