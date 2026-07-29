<?php

declare(strict_types=1);

namespace App\Invitations;

use App\Models\Project;
use App\Support\SystemTenantRegistry;
use Illuminate\Support\Facades\Schema;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\Invitations\Models\InviteCode;
use Padosoft\Invitations\Services\CodeNormalizer;
use Padosoft\Invitations\Services\CodeValidator;
use Padosoft\Invitations\Support\InviteGrant;
use Padosoft\Invitations\Support\RedemptionError;

/**
 * Resolve the tenant namespace of a code entered on the public registration
 * form, then validate it through the package's normal advisory validator.
 *
 * The initial unscoped lookup is deliberate and safe: `invite_codes.code` is
 * globally unique, the code itself is an opaque credential, and every failure
 * is collapsed to the package's generic invalid response. Once located, every
 * subsequent read is explicitly tenant-scoped.
 */
final class RegistrationCodeResolver
{
    public function __construct(
        private readonly CodeNormalizer $normalizer,
        private readonly CodeValidator $validator,
    ) {}

    public function resolve(string $rawCode): RegistrationCodeResolution
    {
        $normalized = $this->normalizer->normalize($rawCode);
        if ($normalized === '') {
            return RegistrationCodeResolution::invalid();
        }

        $matches = InviteCode::query()
            ->where('code', $normalized)
            ->limit(2)
            ->get();
        if ($matches->count() !== 1) {
            return RegistrationCodeResolution::invalid();
        }

        /** @var InviteCode $code */
        $code = $matches->first();
        $redemptionTenant = (string) $code->tenant_id;
        $validation = $this->validator->validate($rawCode, $redemptionTenant);
        if (! $validation->ok) {
            return RegistrationCodeResolution::invalid($validation->error);
        }

        if (SystemTenantRegistry::isSystem($redemptionTenant)) {
            return $this->resolveSystemCode($code);
        }

        // `default` is a legacy storage fallback, never a registration target.
        if (SystemTenantRegistry::isReserved($redemptionTenant)) {
            return RegistrationCodeResolution::invalid();
        }

        if (! $this->isActiveOperationalTenant($redemptionTenant)) {
            return RegistrationCodeResolution::invalid(RedemptionError::Ineligible);
        }

        $grant = InviteGrant::resolve($code->grant, $code->campaign?->grant);
        $tenantGrants = $grant->effectiveTenantGrants($redemptionTenant);
        if (count($tenantGrants) !== 1) {
            return RegistrationCodeResolution::invalid(RedemptionError::Ineligible);
        }
        $tenantGrant = $tenantGrants[0];
        if (
            $tenantGrant->tenantId !== $redemptionTenant
            || $tenantGrant->projects === []
            || ! $this->projectsBelongToTenant($redemptionTenant, $tenantGrant->projects)
        ) {
            return RegistrationCodeResolution::invalid(RedemptionError::Ineligible);
        }

        return RegistrationCodeResolution::valid(
            $code,
            $redemptionTenant,
            RegistrationCodeResolution::TENANT_JOIN,
            $redemptionTenant,
        );
    }

    private function resolveSystemCode(InviteCode $code): RegistrationCodeResolution
    {
        $metadata = is_array($code->metadata) ? $code->metadata : [];
        $intent = $metadata['registration_intent'] ?? null;
        $grant = InviteGrant::resolve($code->grant, $code->campaign?->grant);

        if ($intent === RegistrationCodeResolution::COMPANY_BOOTSTRAP) {
            if (! $grant->isEmpty()) {
                return RegistrationCodeResolution::invalid();
            }

            return RegistrationCodeResolution::valid(
                $code,
                SystemTenantRegistry::REGISTRATION,
                RegistrationCodeResolution::COMPANY_BOOTSTRAP,
                null,
            );
        }

        if ($intent !== RegistrationCodeResolution::TENANT_JOIN) {
            return RegistrationCodeResolution::invalid();
        }

        // Registration codes stored in the system namespace must use only the
        // explicit multi-tenant grant shape. A top-level legacy role/project
        // would be applied to `system-registration` by the vendor provisioner.
        if ($grant->role !== null || $grant->projects !== [] || count($grant->tenants) !== 1) {
            return RegistrationCodeResolution::invalid();
        }

        $tenantGrant = $grant->tenants[0];
        $targetTenant = $tenantGrant->tenantId;
        if (
            SystemTenantRegistry::isReserved($targetTenant)
            || $tenantGrant->projects === []
            || ! $this->isActiveOperationalTenant($targetTenant)
            || ! $this->projectsBelongToTenant($targetTenant, $tenantGrant->projects)
        ) {
            return RegistrationCodeResolution::invalid(RedemptionError::Ineligible);
        }

        $metadataTarget = $metadata['target_tenant'] ?? null;
        if (! is_string($metadataTarget) || ! hash_equals($targetTenant, $metadataTarget)) {
            return RegistrationCodeResolution::invalid();
        }

        return RegistrationCodeResolution::valid(
            $code,
            SystemTenantRegistry::REGISTRATION,
            RegistrationCodeResolution::TENANT_JOIN,
            $targetTenant,
        );
    }

    private function isActiveOperationalTenant(string $slug): bool
    {
        if (! Schema::hasTable('tenants')) {
            return true;
        }

        $columns = ['status'];
        if (Schema::hasColumn('tenants', 'is_system')) {
            $columns[] = 'is_system';
        }

        $tenant = Tenant::query()->where('slug', $slug)->first($columns);

        return $tenant === null
            || ($tenant->status === 'active' && ! (bool) $tenant->getAttribute('is_system'));
    }

    /**
     * @param list<string> $projectKeys
     */
    private function projectsBelongToTenant(string $tenantId, array $projectKeys): bool
    {
        $unique = array_values(array_unique($projectKeys));

        return Project::query()
            ->forTenant($tenantId)
            ->whereIn('project_key', $unique)
            ->count() === count($unique);
    }
}
