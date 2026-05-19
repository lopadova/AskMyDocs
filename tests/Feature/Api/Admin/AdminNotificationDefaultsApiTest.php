<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationTenantDefault;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.0/W2.3 — admin tenant-defaults grid (GET + PUT).
 *
 * Covers:
 *   - GET shape (event_types, channels, registered_channels,
 *     platform_defaults, defaults).
 *   - GET visible to admin AND super-admin; viewer gets 403.
 *   - PUT super-admin only (403 for admin, 403 for viewer).
 *   - PUT validates event_type + channel against the model enums.
 *   - PUT dedups same-cell duplicates (last wins).
 *   - PUT idempotent on replay.
 *   - Cross-tenant isolation: tenant A's PUT does not touch tenant B.
 *   - 401 on both endpoints unauthenticated.
 *   - Seeder hook: UserController@store seeds notification_preferences
 *     from tenant defaults (when present) and from platform fallback
 *     when no per-tenant override exists.
 */
class AdminNotificationDefaultsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
    }

    public function test_index_returns_shape_with_event_types_channels_and_defaults(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)
            ->getJson('/api/admin/notifications/defaults')
            ->assertOk()
            ->json();

        $this->assertSame(NotificationEvent::eventTypes(), $resp['event_types']);
        $this->assertSame(NotificationPreference::availableChannels(), $resp['channels']);
        $this->assertIsArray($resp['registered_channels']);
        $this->assertIsArray($resp['platform_defaults']);
        $this->assertSame([], $resp['defaults']);
    }

    public function test_index_admin_can_read(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->getJson('/api/admin/notifications/defaults')
            ->assertOk();
    }

    public function test_index_super_admin_can_read(): void
    {
        $super = $this->makeSuperAdmin();
        $this->actingAs($super)
            ->getJson('/api/admin/notifications/defaults')
            ->assertOk();
    }

    public function test_index_viewer_forbidden(): void
    {
        $viewer = $this->makeViewer();
        $this->actingAs($viewer)
            ->getJson('/api/admin/notifications/defaults')
            ->assertForbidden();
    }

    public function test_update_super_admin_upserts_defaults(): void
    {
        $super = $this->makeSuperAdmin();

        $resp = $this->actingAs($super)->putJson('/api/admin/notifications/defaults', [
            'defaults' => [
                ['event_type' => 'kb_doc_created', 'channel' => 'email', 'enabled' => true],
                ['event_type' => 'kb_doc_created', 'channel' => 'discord', 'enabled' => true],
            ],
        ])->assertOk()->json();

        $this->assertCount(2, $resp['defaults']);
        $this->assertDatabaseHas('notification_tenant_defaults', [
            'tenant_id' => 'default',
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => true,
        ]);
    }

    public function test_update_admin_without_super_admin_is_forbidden(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->putJson('/api/admin/notifications/defaults', [
                'defaults' => [
                    ['event_type' => 'kb_doc_created', 'channel' => 'email', 'enabled' => true],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('notification_tenant_defaults', 0);
    }

    public function test_update_viewer_forbidden(): void
    {
        $viewer = $this->makeViewer();
        $this->actingAs($viewer)
            ->putJson('/api/admin/notifications/defaults', [
                'defaults' => [
                    ['event_type' => 'kb_doc_created', 'channel' => 'email', 'enabled' => true],
                ],
            ])
            ->assertForbidden();
    }

    public function test_update_rejects_unknown_event_type(): void
    {
        $super = $this->makeSuperAdmin();
        $this->actingAs($super)
            ->putJson('/api/admin/notifications/defaults', [
                'defaults' => [
                    ['event_type' => 'not_a_real_event', 'channel' => 'email', 'enabled' => true],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_update_rejects_unknown_channel(): void
    {
        $super = $this->makeSuperAdmin();
        $this->actingAs($super)
            ->putJson('/api/admin/notifications/defaults', [
                'defaults' => [
                    ['event_type' => 'kb_doc_created', 'channel' => 'pigeon', 'enabled' => true],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_update_dedups_same_cell_last_wins(): void
    {
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)
            ->putJson('/api/admin/notifications/defaults', [
                'defaults' => [
                    ['event_type' => 'kb_doc_created', 'channel' => 'email', 'enabled' => true],
                    ['event_type' => 'kb_doc_created', 'channel' => 'email', 'enabled' => false],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('notification_tenant_defaults', [
            'tenant_id' => 'default',
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => false,
        ]);
        $this->assertSame(
            1,
            NotificationTenantDefault::query()
                ->where('tenant_id', 'default')
                ->where('event_type', 'kb_doc_created')
                ->where('channel', 'email')
                ->count(),
        );
    }

    public function test_update_is_idempotent_on_replay(): void
    {
        $super = $this->makeSuperAdmin();
        $body = [
            'defaults' => [
                ['event_type' => 'kb_doc_modified', 'channel' => 'slack', 'enabled' => true],
            ],
        ];

        $this->actingAs($super)->putJson('/api/admin/notifications/defaults', $body)->assertOk();
        $this->actingAs($super)->putJson('/api/admin/notifications/defaults', $body)->assertOk();

        $this->assertSame(
            1,
            NotificationTenantDefault::query()
                ->where('tenant_id', 'default')
                ->where('event_type', 'kb_doc_modified')
                ->where('channel', 'slack')
                ->count(),
        );
    }

    public function test_update_is_scoped_to_active_tenant(): void
    {
        $super = $this->makeSuperAdmin();

        NotificationTenantDefault::query()->create([
            'tenant_id' => 'tenant-foreign',
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => true,
        ]);

        $this->actingAs($super)
            ->putJson('/api/admin/notifications/defaults', [
                'defaults' => [
                    ['event_type' => 'kb_doc_created', 'channel' => 'email', 'enabled' => false],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('notification_tenant_defaults', [
            'tenant_id' => 'tenant-foreign',
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_tenant_defaults', [
            'tenant_id' => 'default',
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => false,
        ]);
    }

    public function test_index_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/admin/notifications/defaults')->assertStatus(401);
    }

    public function test_update_unauthenticated_returns_401(): void
    {
        $this->putJson('/api/admin/notifications/defaults', ['defaults' => []])->assertStatus(401);
    }

    public function test_user_create_seeds_preferences_from_tenant_defaults_when_present(): void
    {
        // Super-admin pre-seeds tenant defaults: enable email for
        // kb_doc_created, disable everything else.
        NotificationTenantDefault::query()->create([
            'tenant_id' => 'default',
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => true,
        ]);

        $admin = $this->makeAdmin();
        $payload = [
            'name' => 'New Person',
            'email' => 'new-'.uniqid().'@demo.local',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'roles' => ['viewer'],
        ];

        $resp = $this->actingAs($admin)
            ->postJson('/api/admin/users', $payload)
            ->assertCreated();

        $newUserId = $resp->json('data.id');
        $this->assertDatabaseHas('notification_preferences', [
            'tenant_id' => 'default',
            'user_id' => $newUserId,
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => true,
        ]);
        // No override for slack — falls back to platform default
        // (config defines slack=false), so the row is created disabled.
        $this->assertDatabaseHas('notification_preferences', [
            'tenant_id' => 'default',
            'user_id' => $newUserId,
            'event_type' => 'kb_doc_created',
            'channel' => 'slack',
            'enabled' => false,
        ]);
    }

    public function test_user_create_seeds_from_platform_defaults_when_no_tenant_overrides(): void
    {
        // No notification_tenant_defaults rows → seeder uses the
        // `config('askmydocs.notifications.default_channel_preferences')`
        // map (in_app=true, others=false).
        $admin = $this->makeAdmin();
        $payload = [
            'name' => 'Plain User',
            'email' => 'plain-'.uniqid().'@demo.local',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'roles' => ['viewer'],
        ];

        $resp = $this->actingAs($admin)
            ->postJson('/api/admin/users', $payload)
            ->assertCreated();

        $newUserId = $resp->json('data.id');
        $this->assertDatabaseHas('notification_preferences', [
            'tenant_id' => 'default',
            'user_id' => $newUserId,
            'event_type' => 'kb_doc_created',
            'channel' => 'in_app',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'tenant_id' => 'default',
            'user_id' => $newUserId,
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => false,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAdmin(): User
    {
        $u = User::create([
            'name' => 'A',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('admin');

        return $u;
    }

    private function makeSuperAdmin(): User
    {
        $u = User::create([
            'name' => 'S',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('super-admin');

        return $u;
    }

    private function makeViewer(): User
    {
        $u = User::create([
            'name' => 'V',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('viewer');

        return $u;
    }
}
