<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KnowledgeDocument;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\ChannelRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Notifications\RecordingChannel;
use Tests\TestCase;

/**
 * v8.7/W2 — `kb:stale-review-sweep`.
 *
 * Pins: stale docs flag their eligible reviewers; fresh docs don't;
 * already-flagged docs are skipped (idempotent per content version);
 * dry-run notifies nothing; months=0 disables the sweep.
 */
final class KbStaleReviewSweepCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        app(ChannelRegistry::class)->register(new RecordingChannel(NotificationPreference::CHANNEL_IN_APP));
    }

    public function test_flags_a_stale_document_and_marks_it_notified(): void
    {
        $member = $this->makeMember('reviewer', 'proj-stale');
        $this->enableStalePref($member);
        $doc = $this->makeDoc('proj-stale', 'docs/old.md', 'old-1', monthsOld: 8);

        $this->artisan('kb:stale-review-sweep')->assertExitCode(0);

        $row = NotificationEvent::where('event_type', NotificationEvent::EVENT_KB_DOC_STALE_REVIEW)->sole();
        $this->assertSame($member->id, $row->user_id);
        $this->assertSame('proj-stale', $row->payload['project_key'] ?? null);
        $this->assertIsInt($row->payload['age_days'] ?? null);

        // Marker stamped so a second sweep is a no-op for this version.
        $doc->refresh();
        $this->assertArrayHasKey('stale_review_notified_at', (array) $doc->metadata);

        NotificationEvent::query()->delete();
        $this->artisan('kb:stale-review-sweep')->assertExitCode(0);
        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_does_not_flag_a_fresh_document(): void
    {
        $member = $this->makeMember('reviewer', 'proj-fresh');
        $this->enableStalePref($member);
        $this->makeDoc('proj-fresh', 'docs/new.md', 'new-1', monthsOld: 1);

        $this->artisan('kb:stale-review-sweep')->assertExitCode(0);

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_dry_run_notifies_nothing_and_sets_no_marker(): void
    {
        $member = $this->makeMember('reviewer', 'proj-dry');
        $this->enableStalePref($member);
        $doc = $this->makeDoc('proj-dry', 'docs/dry.md', 'dry-1', monthsOld: 9);

        $this->artisan('kb:stale-review-sweep', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, NotificationEvent::count());
        $doc->refresh();
        $this->assertArrayNotHasKey('stale_review_notified_at', (array) $doc->metadata);
    }

    public function test_months_zero_disables_the_sweep(): void
    {
        $member = $this->makeMember('reviewer', 'proj-off');
        $this->enableStalePref($member);
        $this->makeDoc('proj-off', 'docs/off.md', 'off-1', monthsOld: 12);

        $this->artisan('kb:stale-review-sweep', ['--months' => 0])->assertExitCode(0);

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_archived_versions_are_not_swept(): void
    {
        $member = $this->makeMember('reviewer', 'proj-arch');
        $this->enableStalePref($member);
        $this->makeDoc('proj-arch', 'docs/arch.md', 'arch-1', monthsOld: 10, status: 'archived');

        $this->artisan('kb:stale-review-sweep')->assertExitCode(0);

        $this->assertSame(0, NotificationEvent::count());
    }

    private function makeDoc(
        string $projectKey,
        string $sourcePath,
        string $seed,
        int $monthsOld,
        string $status = 'active',
    ): KnowledgeDocument {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'source_type' => 'markdown',
            'title' => 'Doc '.$seed,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => hash('sha256', $seed),
            'version_hash' => hash('sha256', $seed),
            'metadata' => [],
            'indexed_at' => now()->subMonths($monthsOld),
        ]);
    }

    private function enableStalePref(User $user): void
    {
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_STALE_REVIEW,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);
    }

    private function makeMember(string $slug, string $projectKey): User
    {
        $user = User::create([
            'name' => "stale-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => $projectKey,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        return $user;
    }
}
