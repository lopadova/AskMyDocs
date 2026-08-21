<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\TenantBranding;
use App\Models\User;
use App\Services\Admin\TeamRegistryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TeamLogoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware(['api', \App\Http\Middleware\ResolveTenant::class])
            ->prefix('api')
            ->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        Storage::fake('tenant-logos');
        config()->set('tenant-branding.disk', 'tenant-logos');
    }

    public function test_admin_uploads_logo_and_it_appears_in_team_and_me_payloads(): void
    {
        $admin = $this->admin('admin');
        app(TeamRegistryService::class)->create('acme', 'Acme Corp', $admin);

        $response = $this->actingAs($admin)
            ->withHeaders(['X-Tenant-Id' => 'acme', 'Accept' => 'application/json'])
            ->post('/api/admin/teams/acme/logo', ['logo' => $this->png('acme.png')])
            ->assertOk()
            ->assertJsonPath('data.mime_type', 'image/png');

        $url = $response->json('data.logo_url');
        $this->assertIsString($url);
        $branding = TenantBranding::query()->forTenant('acme')->firstOrFail();
        Storage::disk('tenant-logos')->assertExists($branding->logo_path);

        $this->actingAs($admin)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('teams.0.tenant_id', 'acme')
            ->assertJsonPath('teams.0.logo_url', $url);

        $this->actingAs($admin)->get('/api/tenant-logos/acme')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_replacement_deletes_previous_object_and_delete_clears_branding(): void
    {
        $admin = $this->admin('admin');
        app(TeamRegistryService::class)->create('acme', 'Acme Corp', $admin);

        $this->actingAs($admin)->withHeaders(['X-Tenant-Id' => 'acme', 'Accept' => 'application/json'])
            ->post('/api/admin/teams/acme/logo', ['logo' => $this->png('first.png')])
            ->assertOk();
        $first = TenantBranding::query()->forTenant('acme')->firstOrFail()->logo_path;

        $this->actingAs($admin)->withHeaders(['X-Tenant-Id' => 'acme', 'Accept' => 'application/json'])
            ->post('/api/admin/teams/acme/logo', ['logo' => $this->png('second.png')])
            ->assertOk();
        $second = TenantBranding::query()->forTenant('acme')->firstOrFail()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('tenant-logos')->assertMissing($first);
        Storage::disk('tenant-logos')->assertExists($second);

        $this->actingAs($admin)->withHeader('X-Tenant-Id', 'acme')
            ->deleteJson('/api/admin/teams/acme/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);

        $this->assertDatabaseMissing('tenant_brandings', ['tenant_id' => 'acme']);
        Storage::disk('tenant-logos')->assertMissing($second);
    }

    public function test_target_tenant_is_authorized_independently_of_active_header(): void
    {
        $owner = $this->admin('admin');
        app(TeamRegistryService::class)->create('foreign', 'Foreign', $owner);

        $attacker = $this->admin('admin');
        app(TeamRegistryService::class)->create('mine', 'Mine', $attacker);

        $this->actingAs($attacker)->withHeaders(['X-Tenant-Id' => 'mine', 'Accept' => 'application/json'])
            ->post('/api/admin/teams/foreign/logo', ['logo' => $this->png('attack.png')])
            ->assertNotFound();

        $this->actingAs($attacker)->get('/api/tenant-logos/foreign')->assertNotFound();
        $this->assertDatabaseMissing('tenant_brandings', ['tenant_id' => 'foreign']);
    }

    public function test_desktop_bearer_token_can_fetch_an_authorized_tenant_logo(): void
    {
        $admin = $this->admin('admin');
        app(TeamRegistryService::class)->create('acme', 'Acme Corp', $admin);

        $path = 'acme/logo.png';
        Storage::disk('tenant-logos')->put($path, $this->pngBytes());
        TenantBranding::query()->create([
            'tenant_id' => 'acme',
            'logo_path' => $path,
            'mime_type' => 'image/png',
            'original_name' => 'logo.png',
        ]);

        $token = $admin->createToken('desktop-test', ['kb:read'])->plainTextToken;

        $this->withToken($token)
            ->get('/api/tenant-logos/acme')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_upload_rejects_non_image_files(): void
    {
        $admin = $this->admin('admin');
        app(TeamRegistryService::class)->create('acme', 'Acme Corp', $admin);

        $this->actingAs($admin)->withHeaders(['X-Tenant-Id' => 'acme', 'Accept' => 'application/json'])
            ->post('/api/admin/teams/acme/logo', [
                'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg onload="alert(1)"/>'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
    }

    private function admin(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => uniqid($role.'-', true).'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes());
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        return $bytes === false ? '' : $bytes;
    }
}
