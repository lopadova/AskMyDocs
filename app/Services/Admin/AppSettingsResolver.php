<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AppSetting;
use App\Support\AppSettings\AppSettingRegistry;
use Illuminate\Validation\ValidationException;

/**
 * v8.22 (Ciclo 3) — the SINGLE core for runtime configuration governance (R44).
 *
 * Resolves a governable key for a (tenant, project) by layering:
 *   config/env default  ←  tenant row (project_key='*')  ←  exact-project row
 * (pattern: {@see \App\Services\Kb\Analysis\ChangeAnalysisGate}). Only keys in
 * {@see AppSettingRegistry} are recognised; deploy-only keys are read-only.
 *
 * Tenant-scoped (R30) on every query. A small per-instance memo avoids
 * re-querying the same key within a request (the resolver is a singleton, so
 * the AI hot path pays at most one query per key per request).
 */
final class AppSettingsResolver
{
    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * The effective value of a governable key after layering, cast to its type.
     */
    public function effective(string $key, string $tenantId, string $projectKey = AppSetting::WILDCARD): mixed
    {
        $descriptor = AppSettingRegistry::get($key);
        if ($descriptor === null) {
            return null;
        }

        $memoKey = "{$tenantId}\0{$projectKey}\0{$key}";
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $default = config((string) $descriptor['config']);

        $rows = AppSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('setting_key', $key)
            ->whereIn('project_key', array_unique([AppSetting::WILDCARD, $projectKey]))
            ->get()
            ->keyBy('project_key');

        // exact-project overrides tenant '*' overrides the config default.
        $raw = $rows->get($projectKey)?->value_json
            ?? $rows->get(AppSetting::WILDCARD)?->value_json
            ?? $default;

        return $this->memo[$memoKey] = $this->cast($raw, (string) $descriptor['type']);
    }

    /**
     * Every governable key with its effective value + provenance, for the
     * governance UI / CLI / MCP read surface.
     *
     * @return list<array<string,mixed>>
     */
    public function all(string $tenantId, string $projectKey = AppSetting::WILDCARD): array
    {
        $out = [];
        foreach (AppSettingRegistry::all() as $key => $d) {
            $project = AppSetting::query()
                ->where('tenant_id', $tenantId)->where('setting_key', $key)
                ->where('project_key', $projectKey)->value('value_json');
            $wildcard = AppSetting::query()
                ->where('tenant_id', $tenantId)->where('setting_key', $key)
                ->where('project_key', AppSetting::WILDCARD)->value('value_json');

            $source = $projectKey !== AppSetting::WILDCARD && $project !== null
                ? 'project'
                : ($wildcard !== null ? 'tenant' : 'config');

            $out[] = [
                'key' => $key,
                'label' => $d['label'],
                'type' => $d['type'],
                'scope' => $d['scope'],
                'deploy_only' => (bool) $d['deployOnly'],
                'enum' => $d['enum'] ?? null,
                'value' => $this->effective($key, $tenantId, $projectKey),
                'source' => $source,
            ];
        }

        return $out;
    }

    /**
     * Set (or clear, when $value === null) a governable key. Validates the key
     * exists, is not deploy-only, and the value is valid for its type — throwing
     * ValidationException (→ 422) otherwise.
     */
    public function set(string $key, mixed $value, string $tenantId, string $projectKey = AppSetting::WILDCARD): void
    {
        $descriptor = AppSettingRegistry::get($key);
        if ($descriptor === null) {
            throw ValidationException::withMessages(['key' => ["Unknown setting '{$key}'."]]);
        }
        if ((bool) $descriptor['deployOnly']) {
            throw ValidationException::withMessages(['key' => ["'{$key}' is deploy-managed and cannot be set at runtime."]]);
        }

        if ($value === null) {
            AppSetting::query()
                ->where('tenant_id', $tenantId)->where('project_key', $projectKey)
                ->where('setting_key', $key)->delete();
            $this->memo = [];

            return;
        }

        $coerced = $this->validateValue($value, $descriptor);

        AppSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'project_key' => $projectKey, 'setting_key' => $key],
            ['value_json' => $coerced],
        );
        $this->memo = [];
    }

    private function cast(mixed $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw,
            default => (string) $raw,
        };
    }

    /**
     * @param  array<string,mixed>  $descriptor
     */
    private function validateValue(mixed $value, array $descriptor): mixed
    {
        $type = (string) $descriptor['type'];

        if ($type === 'enum') {
            $options = (array) ($descriptor['enum'] ?? []);
            if (! in_array($value, $options, true)) {
                throw ValidationException::withMessages(['value' => ['Value must be one of: '.implode(', ', $options).'.']]);
            }

            return (string) $value;
        }

        if ($type === 'int') {
            if (! is_numeric($value)) {
                throw ValidationException::withMessages(['value' => ['Value must be an integer.']]);
            }
            $int = (int) $value;
            $min = $descriptor['min'] ?? null;
            $max = $descriptor['max'] ?? null;
            if (($min !== null && $int < $min) || ($max !== null && $int > $max)) {
                throw ValidationException::withMessages(['value' => ["Value must be between {$min} and {$max}."]]);
            }

            return $int;
        }

        if ($type === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (string) $value;
    }
}
