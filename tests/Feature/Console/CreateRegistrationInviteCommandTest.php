<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Invitations\RegistrationCodeResolution;
use App\Invitations\RegistrationCodeResolver;
use App\Models\Project;
use App\Support\SystemTenantRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\Invitations\Models\InviteCode;
use Padosoft\Invitations\Services\CodeGenerator;
use Padosoft\Invitations\Support\CodeGenerationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CreateRegistrationInviteCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('viewer', 'web');
        Role::findOrCreate('admin', 'web');
    }

    public function test_command_without_tenant_creates_a_bootstrap_code(): void
    {
        $this->artisan('registration-invite:create', [
            '--uses' => 3,
            '--expires' => now()->addDay()->toIso8601String(),
        ])
            ->expectsOutputToContain('Registration invite created.')
            ->expectsOutputToContain('company_bootstrap')
            ->assertSuccessful();

        $code = InviteCode::query()->firstOrFail();
        $this->assertSame(SystemTenantRegistry::REGISTRATION, $code->tenant_id);
        $this->assertSame(3, $code->max_uses);
        $this->assertSame([], $code->grant);
        $this->assertSame(
            RegistrationCodeResolution::COMPANY_BOOTSTRAP,
            $code->metadata['registration_intent'],
        );

        $resolution = app(RegistrationCodeResolver::class)->resolve($code->code);
        $this->assertTrue($resolution->ok);
        $this->assertSame(RegistrationCodeResolution::COMPANY_BOOTSTRAP, $resolution->intent);
        $this->assertNull($resolution->targetTenant);
    }

    public function test_command_with_tenant_creates_one_explicit_tenant_grant(): void
    {
        $this->makeTenant('acme', 'Acme');
        Project::create([
            'tenant_id' => 'acme',
            'project_key' => 'acme-kb',
            'name' => 'Acme KB',
        ]);

        $this->artisan('registration-invite:create', [
            '--tenant' => 'acme',
            '--project' => ['acme-kb'],
            '--role' => 'admin',
            '--membership-role' => 'admin',
        ])
            ->expectsOutputToContain('tenant_join')
            ->expectsOutputToContain('acme')
            ->assertSuccessful();

        $code = InviteCode::query()->firstOrFail();
        $this->assertSame(SystemTenantRegistry::REGISTRATION, $code->tenant_id);
        $this->assertArrayNotHasKey('role', $code->grant);
        $this->assertArrayNotHasKey('projects', $code->grant);
        $this->assertSame('acme', $code->grant['tenants'][0]['tenant_id']);
        $this->assertSame(['acme-kb'], $code->grant['tenants'][0]['projects']);
        $this->assertSame('admin', $code->grant['tenants'][0]['role']);

        $resolution = app(RegistrationCodeResolver::class)->resolve($code->code);
        $this->assertTrue($resolution->ok);
        $this->assertSame(RegistrationCodeResolution::TENANT_JOIN, $resolution->intent);
        $this->assertSame('acme', $resolution->targetTenant);
    }

    public function test_tenant_invite_rejects_unknown_projects(): void
    {
        $this->makeTenant('acme', 'Acme');

        $this->artisan('registration-invite:create', [
            '--tenant' => 'acme',
            '--project' => ['missing'],
        ])
            ->expectsOutputToContain('Unknown project(s) for this tenant: missing.')
            ->assertFailed();

        $this->assertDatabaseCount('invite_codes', 0);
    }

    public function test_resolver_accepts_a_legacy_tenant_local_code_but_rejects_default(): void
    {
        $this->makeTenant('acme', 'Acme');

        $legacy = app(CodeGenerator::class)->generateRandom([
            'tenant_id' => 'acme',
            'grant' => [],
        ]);
        $accepted = app(RegistrationCodeResolver::class)->resolve($legacy->code);
        $this->assertTrue($accepted->ok);
        $this->assertSame('acme', $accepted->targetTenant);

        $default = app(CodeGenerator::class)->generateRandom([
            'tenant_id' => 'default',
            'grant' => [],
        ]);
        $this->assertFalse(app(RegistrationCodeResolver::class)->resolve($default->code)->ok);
    }

    public function test_invite_code_is_globally_unique_across_tenant_namespaces(): void
    {
        $context = app(TenantContext::class);
        $context->set('acme');
        app(CodeGenerator::class)->mintVanity('JOINACME');

        $context->set('globex');
        $this->expectException(CodeGenerationException::class);
        app(CodeGenerator::class)->mintVanity('JOINACME');
    }

    private function makeTenant(string $slug, string $name): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'name' => $name,
            'status' => 'active',
            'is_system' => false,
        ]);
    }
}
