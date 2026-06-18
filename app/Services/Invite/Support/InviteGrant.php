<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

/**
 * The provisioning grant an invite key carries: what the redeemer's account
 * becomes on a fresh claim.
 *
 * Pure value object — no DB, no validation against the roles table (that lives
 * in the FormRequest, against the real domain, R18). It only models the shape
 * and the campaign→code resolution rule:
 *
 *   - `role`           the single Spatie role the redeemer is granted (additive
 *                      at apply time — never a downgrade). super-admin is never
 *                      grantable via a code (enforced upstream in validation).
 *   - `projects`       tenant project_keys the redeemer gains membership on.
 *   - `projectRole`    the membership role written per project (member/admin/owner).
 *   - `scopeAllowlist` optional per-project scope restriction (folder_globs/tags).
 *
 * An empty grant (no role and no projects) provisions nothing — the redemption
 * still succeeds, it just creates/links no access.
 */
final class InviteGrant
{
    /**
     * @param  list<string>  $projects
     * @param  array<string, mixed>|null  $scopeAllowlist
     */
    public function __construct(
        public readonly ?string $role = null,
        public readonly array $projects = [],
        public readonly string $projectRole = 'member',
        public readonly ?array $scopeAllowlist = null,
    ) {
    }

    /**
     * Build from a stored grant map (campaign/code `grant` column). Tolerant of
     * a null/partial map; unknown keys are ignored. Project keys are coerced to
     * a clean, de-duplicated, non-empty string list.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self();
        }

        $role = isset($data['role']) && is_string($data['role']) && $data['role'] !== ''
            ? $data['role']
            : null;

        $projects = [];
        if (isset($data['projects']) && is_array($data['projects'])) {
            foreach ($data['projects'] as $project) {
                if (is_string($project) && trim($project) !== '') {
                    $projects[] = trim($project);
                }
            }
        }
        /** @var list<string> $projects */
        $projects = array_values(array_unique($projects));

        $projectRole = isset($data['project_role']) && is_string($data['project_role']) && $data['project_role'] !== ''
            ? $data['project_role']
            : 'member';

        $scopeAllowlist = isset($data['scope_allowlist']) && is_array($data['scope_allowlist'])
            ? $data['scope_allowlist']
            : null;

        return new self($role, $projects, $projectRole, $scopeAllowlist);
    }

    /**
     * Resolve the effective grant for a code: a non-empty code grant wins over
     * the campaign default; otherwise inherit the campaign grant. Either side
     * may be null.
     *
     * @param  array<string, mixed>|null  $codeGrant
     * @param  array<string, mixed>|null  $campaignGrant
     */
    public static function resolve(?array $codeGrant, ?array $campaignGrant): self
    {
        $code = self::fromArray($codeGrant);

        return $code->isEmpty() ? self::fromArray($campaignGrant) : $code;
    }

    public function isEmpty(): bool
    {
        return $this->role === null && $this->projects === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'projects' => $this->projects,
            'project_role' => $this->projectRole,
            'scope_allowlist' => $this->scopeAllowlist,
        ];
    }
}
