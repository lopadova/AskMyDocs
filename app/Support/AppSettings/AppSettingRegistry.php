<?php

declare(strict_types=1);

namespace App\Support\AppSettings;

/**
 * v8.22 (Ciclo 3) — the curated catalogue of runtime-governable settings.
 *
 * ONLY keys listed here can be read/written through the governance surface
 * (HTTP / CLI / MCP). Each descriptor declares:
 *   - `type`       — bool | int | string | enum (drives validation + casting)
 *   - `config`     — the Laravel config path supplying the env/deploy DEFAULT
 *   - `scope`      — 'tenant' (one value per tenant) or 'both' (tenant + project)
 *   - `deployOnly` — security-sensitive knobs that are READ-only here: surfaced
 *                    for visibility but never settable at runtime (FinOps
 *                    enforcement, master security switches stay deploy-managed)
 *   - `enum`/`min`/`max` — validation bounds where applicable
 *
 * Secrets are NEVER registered here — they live only in the encrypted vault.
 */
final class AppSettingRegistry
{
    /**
     * @return array<string, array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            // Flagship: AI chat provider per tenant (overrides ai.default).
            'ai.provider' => [
                'label' => 'AI chat provider',
                'type' => 'enum',
                'config' => 'ai.default',
                'scope' => 'tenant',
                'deployOnly' => false,
                'enum' => ['openai', 'anthropic', 'gemini', 'openrouter', 'regolo'],
            ],
            // Connector sync cadence (minutes) — governable per tenant.
            'connector.sync_cadence_minutes' => [
                'label' => 'Connector sync cadence (minutes)',
                'type' => 'int',
                'config' => 'connectors.default_sync_cadence_minutes',
                'scope' => 'tenant',
                'deployOnly' => false,
                'min' => 5,
                'max' => 1440,
            ],
            // Deploy-only security knob — visible but NOT runtime-settable.
            'ai_finops.enabled' => [
                'label' => 'AI FinOps metering (deploy-managed)',
                'type' => 'bool',
                'config' => 'ai-finops.enabled',
                'scope' => 'tenant',
                'deployOnly' => true,
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function isDeployOnly(string $key): bool
    {
        return (bool) (self::get($key)['deployOnly'] ?? false);
    }
}
