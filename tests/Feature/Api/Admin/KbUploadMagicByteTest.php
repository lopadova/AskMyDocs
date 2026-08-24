<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Boundary decoded-type enforcement on POST /api/admin/kb/uploads
 * (SEC-UPLOAD-001, F-05): the extension / client MIME are attacker-controlled,
 * so a file whose real bytes contradict its declared type is rejected 422.
 */
class KbUploadMagicByteTest extends TestCase
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
        Storage::fake('kb-staging');
        Storage::fake('kb');
        // No explicit TenantContext override: the base TestCase pins a real
        // (non-reserved) test tenant, and actingAs() provisions the admin's
        // membership there so the tenant.authorize'd upload route admits it.
        $this->withHeaders(['Accept' => 'application/json']);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'A',
            'email' => 'a-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_rejects_a_pdf_named_file_that_is_not_a_pdf(): void
    {
        $this->actingAs($this->admin())->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [UploadedFile::fake()->createWithContent('report.pdf', '<html>not a pdf</html>')],
        ])->assertStatus(422)->assertJsonValidationErrorFor('files.0');
    }

    public function test_rejects_binary_smuggled_under_a_markdown_name(): void
    {
        $this->actingAs($this->admin())->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [UploadedFile::fake()->createWithContent('notes.md', "%PDF-1.7 hidden binary")],
        ])->assertStatus(422)->assertJsonValidationErrorFor('files.0');
    }

    public function test_accepts_a_real_pdf(): void
    {
        $this->actingAs($this->admin())->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.7\n%real pdf bytes\n")],
        ])->assertStatus(201);
    }

    public function test_accepts_a_real_markdown_file(): void
    {
        $this->actingAs($this->admin())->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [UploadedFile::fake()->createWithContent('guide.md', "# Guide\n\nReal markdown.")],
        ])->assertStatus(201);
    }
}
