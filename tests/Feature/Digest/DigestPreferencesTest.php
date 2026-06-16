<?php

declare(strict_types=1);

namespace Tests\Feature\Digest;

use App\Mail\DigestMail;
use App\Models\DigestPreference;
use App\Models\EngagementDigestFeedEntry;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v8.15/W3 — digest preferences (cadence + sections) + frequency-filtered
 * delivery + the in-app feed.
 */
final class DigestPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
    }

    private function makeUser(): User
    {
        return User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
    }

    /** A user who is email-enabled for notifications, with an optional digest frequency. */
    private function emailUser(?string $frequency = null): User
    {
        $u = $this->makeUser();
        NotificationPreference::create([
            'tenant_id' => 'default', 'user_id' => $u->id,
            'event_type' => 'kb_doc_created', 'channel' => NotificationPreference::CHANNEL_EMAIL, 'enabled' => true,
        ]);
        if ($frequency !== null) {
            DigestPreference::create(['tenant_id' => 'default', 'user_id' => $u->id, 'frequency' => $frequency, 'sections' => null]);
        }

        return $u;
    }

    public function test_show_returns_defaults_without_a_row(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->getJson('/api/me/digest-preferences')
            ->assertOk()
            ->assertJsonPath('frequency', 'weekly')
            ->assertJsonPath('available_frequencies', ['weekly', 'monthly', 'off']);
    }

    public function test_update_persists_frequency_and_sections(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->putJson('/api/me/digest-preferences', [
            'frequency' => 'monthly',
            'sections' => ['metrics', 'top_gaps'],
        ])->assertOk()->assertJsonPath('frequency', 'monthly');

        $this->assertDatabaseHas('digest_preferences', ['user_id' => $user->id, 'frequency' => 'monthly']);
    }

    public function test_empty_sections_persists_as_none_not_all(): void
    {
        $user = $this->makeUser();
        // Unchecking every box sends [] → stored as "none", round-trips as [].
        $this->actingAs($user)->putJson('/api/me/digest-preferences', [
            'frequency' => 'weekly',
            'sections' => [],
        ])->assertOk()->assertJsonPath('sections', []);

        $this->actingAs($user)->getJson('/api/me/digest-preferences')
            ->assertOk()->assertJsonPath('sections', []);
    }

    public function test_null_sections_means_all(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->putJson('/api/me/digest-preferences', [
            'frequency' => 'weekly',
            'sections' => null,
        ])->assertOk()->assertJsonPath('sections', DigestPreference::SECTIONS);
    }

    public function test_update_rejects_invalid_frequency(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->putJson('/api/me/digest-preferences', ['frequency' => 'hourly'])
            ->assertStatus(422);
    }

    public function test_guest_cannot_read_preferences(): void
    {
        $this->getJson('/api/me/digest-preferences')->assertUnauthorized();
    }

    public function test_weekly_send_respects_frequency_preference(): void
    {
        Mail::fake();
        $this->emailUser(null);        // default → weekly: included
        $this->emailUser('weekly');    // explicit weekly: included
        $this->emailUser('monthly');   // monthly: excluded from weekly run
        $this->emailUser('off');       // off: excluded

        $this->artisan('digest:send', ['--tenant' => 'default', '--channel' => 'email'])->assertExitCode(0);

        Mail::assertQueued(DigestMail::class, 2);
    }

    public function test_monthly_send_targets_monthly_subscribers_only(): void
    {
        Mail::fake();
        $this->emailUser(null);        // default weekly: excluded from monthly run
        $this->emailUser('monthly');   // included

        $this->artisan('digest:send', ['--tenant' => 'default', '--channel' => 'email', '--frequency' => 'monthly'])->assertExitCode(0);

        Mail::assertQueued(DigestMail::class, 1);
    }

    public function test_send_persists_feed_entry_and_latest_endpoint_returns_it(): void
    {
        Mail::fake();
        config()->set('kb.digest.ai_narrative_enabled', false);
        $this->artisan('digest:send', ['--tenant' => 'default', '--channel' => 'email'])->assertExitCode(0);

        $this->assertDatabaseCount('engagement_digest_feed', 1);

        $user = $this->makeUser();
        $this->actingAs($user)->getJson('/api/me/digest/latest')
            ->assertOk()
            ->assertJsonPath('has_digest', true)
            ->assertJsonStructure(['digest' => ['metrics', 'new_docs', 'top_gaps'], 'enabled_sections']);
    }

    public function test_prune_feed_drops_entries_past_retention(): void
    {
        EngagementDigestFeedEntry::create([
            'tenant_id' => 'default', 'frequency' => 'weekly',
            'period_start' => now()->subDays(200)->toDateString(), 'period_end' => now()->subDays(193)->toDateString(),
            'payload' => ['metrics' => []], 'created_at' => now()->subDays(190),
        ]);
        EngagementDigestFeedEntry::create([
            'tenant_id' => 'default', 'frequency' => 'weekly',
            'period_start' => now()->subDays(7)->toDateString(), 'period_end' => now()->toDateString(),
            'payload' => ['metrics' => []], 'created_at' => now(),
        ]);

        $this->artisan('digest:prune-feed', ['--days' => 120])->assertExitCode(0);

        $this->assertDatabaseCount('engagement_digest_feed', 1);
    }

    public function test_feed_is_tenant_isolated(): void
    {
        EngagementDigestFeedEntry::create([
            'tenant_id' => 'tenant-a', 'frequency' => 'weekly',
            'period_start' => now()->subDays(7)->toDateString(), 'period_end' => now()->toDateString(),
            'payload' => ['metrics' => [], 'new_docs' => [], 'top_gaps' => []], 'created_at' => now(),
        ]);

        // A user in the default tenant must NOT see tenant-a's digest.
        $user = $this->makeUser();
        $this->actingAs($user)->getJson('/api/me/digest/latest')
            ->assertOk()
            ->assertJsonPath('has_digest', false);
    }
}
