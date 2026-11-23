<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Models\Project;
use App\Models\User;
use App\Models\WidgetKey;
use App\Services\Admin\ProjectCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class WidgetKeyIssuer
{
    public function __construct(
        private ProjectCatalogService $projects,
        private WidgetThemeService $theme,
        private WidgetIntroService $intro,
        private WidgetIdentityCredentialService $identityCredentials,
    ) {}

    /** @return array{key: WidgetKey, plain_secret: string, identity_plain_secret: ?string} */
    public function issue(string $tenantId, array $settings, ?User $actor): array
    {
        $introConfig = array_key_exists('intro', $settings) ? $this->intro->sanitize($settings['intro'] ?? []) : null;
        if ($introConfig !== null) {
            $this->intro->assertUsable($introConfig);
        }

        $plainSecret = 'sk_'.Str::random(40);

        return DB::transaction(function () use ($tenantId, $settings, $actor, $introConfig, $plainSecret): array {
            // Share the project lock with registry deletion. Legacy projects can
            // also be backed by the catalogue's document, membership or key rows.
            Project::query()->forTenant($tenantId)->where('project_key', $settings['project_key'])
                ->lockForUpdate()->first(['id']);

            if (! $this->projects->contains($settings['project_key'])) {
                throw ValidationException::withMessages([
                    'project_key' => ['The selected project is not available for this tenant.'],
                ]);
            }

            $key = WidgetKey::query()->create([
                'tenant_id' => $tenantId,
                'project_key' => $settings['project_key'],
                'public_key' => 'pk_'.Str::random(32),
                'secret_hash' => Hash::make($plainSecret),
                'label' => $settings['label'],
                'allowed_origins' => $settings['allowed_origins'] ?? [],
                'rate_limit' => $settings['rate_limit'] ?? 60,
                'skill' => $settings['skill'] ?? 'askmydocs-assistant@1',
                'host_tools_enabled' => $settings['host_tools_enabled'] ?? false,
                'data_agent_enabled' => $settings['data_agent_enabled'] ?? true,
                'user_auth_enabled' => false,
                'is_active' => true,
                'theme_config' => isset($settings['theme']) ? $this->theme->sanitize($settings['theme']) : null,
                'intro_config' => $introConfig,
            ]);

            $identitySecret = null;
            if ($settings['user_auth_enabled'] ?? false) {
                $result = $this->identityCredentials->enable((int) $key->id, $tenantId, 0, $actor, WidgetIdentityCredentialService::SURFACE_HTTP);
                $key = $result->key;
                $identitySecret = $result->plainSecret;
            }

            return ['key' => $key, 'plain_secret' => $plainSecret, 'identity_plain_secret' => $identitySecret];
        });
    }
}
