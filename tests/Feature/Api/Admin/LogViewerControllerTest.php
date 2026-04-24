<?php

namespace Tests\Feature\Api\Admin;

use App\Models\ChatLog;
use App\Models\KbCanonicalAudit;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase H1 — admin Log Viewer feature tests.
 *
 * Mirrors the harness used by the dashboard + KB admin suites:
 * defineRoutes() mounts routes/api.php under `api + web` middleware
 * stack, RbacSeeder seeds the canonical admin + viewer roles,
 * Cache::flush() keeps Spatie's permission cache honest between
 * RefreshDatabase rollbacks.
 *
 * R13-adjacent: these are backend-only PHPUnit feature tests, so
 * the "real data" rule does not apply — every scenario seeds what
 * it asserts on, which is the right tradeoff for unit-level signal.
 */
class LogViewerControllerTest extends TestCase
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
    }

    // ------------------------------------------------------------------
    // chat
    // ------------------------------------------------------------------

    public function test_chat_paginates_and_returns_full_shape(): void
    {
        $admin = $this->makeAdmin();

        // Seed 25 rows so we cross the 20/page boundary.
        for ($i = 0; $i < 25; $i++) {
            $this->makeChatLog(['ai_model' => 'gpt-4o']);
        }

        $res = $this->actingAs($admin)
            ->getJson('/api/admin/logs/chat?page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure([
                'data' => [
                    ['id', 'session_id', 'question', 'answer', 'ai_provider', 'ai_model',
                        'prompt_tokens', 'completion_tokens', 'total_tokens',
                        'latency_ms', 'sources', 'extra', 'created_at'],
                ],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $this->assertCount(20, $res->json('data'));
    }

    public function test_chat_filters_by_project_and_model(): void
    {
        $admin = $this->makeAdmin();

        $this->makeChatLog(['project_key' => 'hr-portal', 'ai_model' => 'gpt-4o']);
        $this->makeChatLog(['project_key' => 'hr-portal', 'ai_model' => 'claude-3-5-sonnet']);
        $this->makeChatLog(['project_key' => 'engineering', 'ai_model' => 'gpt-4o']);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/chat?project=hr-portal')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/chat?project=hr-portal&model=gpt-4o')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_chat_filters_by_min_latency_and_min_tokens(): void
    {
        $admin = $this->makeAdmin();

        $this->makeChatLog(['latency_ms' => 100, 'total_tokens' => 10]);
        $this->makeChatLog(['latency_ms' => 500, 'total_tokens' => 50]);
        $this->makeChatLog(['latency_ms' => 1000, 'total_tokens' => 200]);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/chat?min_latency_ms=400')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/chat?min_tokens=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_chat_filters_by_date_range(): void
    {
        $admin = $this->makeAdmin();

        $old = Carbon::now()->subDays(10);
        $recent = Carbon::now()->subDays(1);

        $this->makeChatLog(['created_at' => $old->toDateTimeString()]);
        $this->makeChatLog(['created_at' => $recent->toDateTimeString()]);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/chat?from='.$recent->copy()->subHours(2)->toDateTimeString())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_chat_show_returns_single_row_full_payload(): void
    {
        $admin = $this->makeAdmin();
        $log = $this->makeChatLog(['question' => 'how', 'answer' => 'ok']);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/chat/'.$log->id)
            ->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.question', 'how')
            ->assertJsonPath('data.answer', 'ok');
    }

    public function test_chat_show_404_on_missing_id(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->getJson('/api/admin/logs/chat/999999')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // canonical-audit
    // ------------------------------------------------------------------

    public function test_canonical_audit_filters_by_project_event_actor(): void
    {
        $admin = $this->makeAdmin();

        $this->makeAudit(['project_key' => 'hr-portal', 'event_type' => 'promoted', 'actor' => 'system']);
        $this->makeAudit(['project_key' => 'hr-portal', 'event_type' => 'updated', 'actor' => 'alice']);
        $this->makeAudit(['project_key' => 'engineering', 'event_type' => 'promoted', 'actor' => 'system']);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/canonical-audit?project=hr-portal')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/canonical-audit?event_type=promoted')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/canonical-audit?actor=alice')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // ------------------------------------------------------------------
    // application
    // ------------------------------------------------------------------

    public function test_application_returns_tail_of_seeded_log_file(): void
    {
        $admin = $this->makeAdmin();

        $path = storage_path('logs/laravel.log');
        // Copilot #5 fix: no @-silenced filesystem calls (R7).
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $lines[] = '[2025-01-01 10:0'.$i.':00] local.INFO: line '.$i;
        }
        file_put_contents($path, implode("\n", $lines)."\n");

        try {
            $res = $this->actingAs($admin)
                ->getJson('/api/admin/logs/application?file=laravel.log&tail=5')
                ->assertOk()
                ->assertJsonStructure(['file', 'lines', 'truncated', 'total_scanned']);

            $this->assertSame('laravel.log', $res->json('file'));
            $this->assertCount(5, $res->json('lines'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_application_level_filter_matches_bracketed_level(): void
    {
        $admin = $this->makeAdmin();

        $path = storage_path('logs/laravel.log');
        // Copilot #5 fix: no @-silenced filesystem calls (R7).
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path,
            "[2025-01-01 10:00:00] local.INFO: normal line\n".
            "[2025-01-01 10:01:00] local.ERROR: broken thing\n".
            "[2025-01-01 10:02:00] local.INFO: another line\n".
            "[2025-01-01 10:03:00] local.ERROR: still broken\n"
        );

        try {
            $res = $this->actingAs($admin)
                ->getJson('/api/admin/logs/application?file=laravel.log&level=ERROR')
                ->assertOk();

            $this->assertCount(2, $res->json('lines'));
            foreach ($res->json('lines') as $line) {
                $this->assertStringContainsString('ERROR', $line);
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_application_invalid_filename_returns_422(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/application?file=secrets.txt')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['file']]);
    }

    public function test_application_path_traversal_attempt_returns_422(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/application?file='.urlencode('../../.env'))
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/application?file='.urlencode('../secrets'))
            ->assertStatus(422);
    }

    public function test_application_missing_whitelisted_file_returns_404(): void
    {
        $admin = $this->makeAdmin();

        // Choose a rotated name that's unlikely to exist on disk during tests.
        $this->actingAs($admin)
            ->getJson('/api/admin/logs/application?file=laravel-1999-01-01.log')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // activity
    // ------------------------------------------------------------------

    public function test_activity_returns_empty_note_when_table_missing(): void
    {
        $admin = $this->makeAdmin();

        Schema::dropIfExists('activity_log');

        $res = $this->actingAs($admin)
            ->getJson('/api/admin/logs/activity')
            ->assertOk()
            ->assertJsonPath('note', 'activitylog not installed')
            ->assertJsonPath('data', []);
    }

    public function test_activity_returns_rows_when_seeded(): void
    {
        $admin = $this->makeAdmin();

        if (! Schema::hasTable('activity_log')) {
            $this->markTestSkipped('activity_log table not present in this migration run');
        }

        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'something happened',
            'subject_type' => 'App\\Models\\User',
            'subject_id' => $admin->id,
            'event' => 'created',
            'causer_type' => 'App\\Models\\User',
            'causer_id' => $admin->id,
            'properties' => null,
            'attribute_changes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/activity')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.description', 'something happened');
    }

    // ------------------------------------------------------------------
    // failed-jobs
    // ------------------------------------------------------------------

    public function test_failed_jobs_paginated(): void
    {
        $admin = $this->makeAdmin();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\IngestDocumentJob',
                'data' => ['commandName' => 'App\\Jobs\\IngestDocumentJob'],
                'attempts' => 3,
            ]),
            'exception' => "RuntimeException: boom\n#0 stack",
            'failed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/logs/failed-jobs')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.display_name', 'App\\Jobs\\IngestDocumentJob')
            ->assertJsonPath('data.0.queue', 'default')
            ->assertJsonPath('data.0.attempts', 3);
    }

    // ------------------------------------------------------------------
    // RBAC
    // ------------------------------------------------------------------

    public function test_viewer_gets_403_on_every_endpoint(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $viewer->assignRole('viewer');

        foreach ([
            '/api/admin/logs/chat',
            '/api/admin/logs/chat/1',
            '/api/admin/logs/canonical-audit',
            '/api/admin/logs/application',
            '/api/admin/logs/activity',
            '/api/admin/logs/failed-jobs',
        ] as $path) {
            $this->actingAs($viewer)
                ->getJson($path)
                ->assertStatus(403);
        }
    }

    public function test_guest_gets_401(): void
    {
        foreach ([
            '/api/admin/logs/chat',
            '/api/admin/logs/chat/1',
            '/api/admin/logs/canonical-audit',
            '/api/admin/logs/application',
            '/api/admin/logs/activity',
            '/api/admin/logs/failed-jobs',
        ] as $path) {
            $this->getJson($path)->assertStatus(401);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeChatLog(array $overrides = []): ChatLog
    {
        return ChatLog::create(array_merge([
            'session_id' => (string) Str::uuid(),
            'user_id' => null,
            'question' => 'q',
            'answer' => 'a',
            'project_key' => 'hr-portal',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 0,
            'sources' => [],
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'latency_ms' => 100,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeAudit(array $overrides = []): KbCanonicalAudit
    {
        return KbCanonicalAudit::create(array_merge([
            'project_key' => 'hr-portal',
            'doc_id' => 'dec-'.Str::random(5),
            'slug' => Str::random(5),
            'event_type' => 'promoted',
            'actor' => 'system',
            'before_json' => null,
            'after_json' => ['status' => 'accepted'],
            'metadata_json' => null,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $overrides));
    }
}
