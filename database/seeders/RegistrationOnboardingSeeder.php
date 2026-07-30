<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Invitations\RegistrationCodeResolution;
use App\Models\Project;
use App\Support\SystemTenantRegistry;
use Illuminate\Database\Seeder;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\Invitations\Models\InviteCode;
use Padosoft\Invitations\Services\CodeGenerator;

/**
 * Deterministic registration fixtures for the real-stack Playwright journeys.
 *
 * These codes are testing-only and intentionally minted through the package's
 * generator so normalization, persistence and redemption stay on the same path
 * as production-issued registration invitations.
 */
final class RegistrationOnboardingSeeder extends Seeder
{
    public const BOOTSTRAP_CODE = 'START123';

    public const TENANT_JOIN_CODE = 'J01NACME';

    public const JOIN_TENANT = 'invited-company';

    public const JOIN_PROJECT = 'invited-kb';

    public function run(CodeGenerator $codes): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('RegistrationOnboardingSeeder is available only in the testing environment.');
        }

        $this->call(RbacSeeder::class);
        $this->call(SystemTenantSeeder::class);

        Tenant::query()->updateOrCreate(
            ['slug' => self::JOIN_TENANT],
            [
                'name' => 'Invited Company',
                'status' => 'active',
                'is_system' => false,
            ],
        );
        Project::query()->updateOrCreate(
            [
                'tenant_id' => self::JOIN_TENANT,
                'project_key' => self::JOIN_PROJECT,
            ],
            [
                'name' => 'Invited Knowledge Base',
                'description' => 'Existing company used by registration E2E.',
            ],
        );

        $this->mintIfMissing($codes, self::BOOTSTRAP_CODE, [
            'tenant_id' => SystemTenantRegistry::REGISTRATION,
            'max_uses' => 10,
            'metadata' => [
                'registration_intent' => RegistrationCodeResolution::COMPANY_BOOTSTRAP,
            ],
            'grant' => [],
        ]);

        $this->mintIfMissing($codes, self::TENANT_JOIN_CODE, [
            'tenant_id' => SystemTenantRegistry::REGISTRATION,
            'max_uses' => 10,
            'metadata' => [
                'registration_intent' => RegistrationCodeResolution::TENANT_JOIN,
                'target_tenant' => self::JOIN_TENANT,
            ],
            'grant' => [
                'tenants' => [[
                    'tenant_id' => self::JOIN_TENANT,
                    'role' => 'viewer',
                    'projects' => [self::JOIN_PROJECT],
                    'project_role' => 'member',
                    'scope_allowlist' => null,
                ]],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function mintIfMissing(CodeGenerator $codes, string $code, array $attributes): void
    {
        if (InviteCode::query()->where('code', $code)->exists()) {
            return;
        }

        $codes->mintVanity($code, $attributes);
    }
}
