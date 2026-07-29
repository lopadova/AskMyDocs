<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AdminCommandAudit;
use App\Models\User;
use App\Services\Admin\TeamRegistryService;
use App\Support\PlatformAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Complete the resumable no-tenant registration state.
 *
 * The user row is locked before eligibility is re-checked, serialising double
 * submits from the same account. Tenant registry, initial project, owner
 * membership, tenant super-admin role and completed audit then commit together.
 */
final class CompanyOnboardingService
{
    public function __construct(
        private readonly TeamRegistryService $teams,
        private readonly CompanyOnboardingEligibility $eligibility,
    ) {}

    /**
     * @return array{tenant: array<string,mixed>, project: array<string,mixed>}
     */
    public function complete(
        User $actor,
        string $companyName,
        ?string $tenantSlug = null,
        ?string $projectKey = null,
    ): array {
        $result = DB::transaction(function () use (
            $actor,
            $companyName,
            $tenantSlug,
            $projectKey,
        ): array {
            $lockedUser = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $this->eligibility->required($lockedUser)) {
                throw new CompanyOnboardingNotRequired;
            }

            $team = $this->teams->createForMember(
                $tenantSlug,
                $companyName,
                $lockedUser,
                $projectKey,
                'owner',
            );

            // This trusted onboarding workflow is the only invitation-adjacent
            // path allowed to mint tenant super-admin. Invite grants themselves
            // continue to reject the protected role.
            $lockedUser->assignRole(PlatformAccess::TENANT_SUPER_ADMIN_ROLE);

            AdminCommandAudit::create([
                'tenant_id' => $team['slug'],
                'user_id' => $lockedUser->id,
                'command' => 'auth:onboarding-company',
                'args_json' => [
                    'tenant_slug' => $team['slug'],
                    'project_key' => $projectKey ?: $team['slug'],
                    'surface' => 'http',
                ],
                'status' => AdminCommandAudit::STATUS_COMPLETED,
                'exit_code' => 0,
                'started_at' => now(),
                'completed_at' => now(),
                'client_ip' => request()?->ip(),
                'user_agent' => $this->userAgent(),
            ]);

            return [
                'tenant' => $team,
                'project' => [
                    'project_key' => $projectKey ?: $team['slug'],
                    'name' => trim($companyName),
                    'membership_role' => 'owner',
                ],
            ];
        });

        Log::notice('Company onboarding completed.', [
            'user_id' => $actor->id,
            'tenant_id' => $result['tenant']['slug'],
            'project_key' => $result['project']['project_key'],
        ]);

        return $result;
    }

    private function userAgent(): ?string
    {
        $value = request()?->userAgent();

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
