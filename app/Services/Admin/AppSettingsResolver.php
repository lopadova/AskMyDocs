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
// Intentionally not final — bound as a singleton and overridden with a test
// double in AiManager OFF-path coverage (mirrors the non-final AiManager).
class AppSettingsResolver
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

        // A tenant-scoped key never varies by project: ignore the project row
        // on READ too, so reads stay consistent with writes (set() rejects
        // project overrides) and a stray/legacy project row can't silently
        // change behaviour.
        if (! $this->allowsProjectScope($descriptor)) {
            $projectKey = AppSetting::WILDCARD;
        }

        // The memo serves the AI hot path within a single (short-lived) web
        // request. In a long-lived console process (queue worker, MCP server)
        // it would pin a value until restart and defeat the "runtime" nature of
        // app_settings — so re-query every call there.
        $useMemo = ! app()->runningInConsole();
        $memoKey = "{$tenantId}\0{$projectKey}\0{$key}";
        if ($useMemo && array_key_exists($memoKey, $this->memo)) {
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

        $value = $this->cast($raw, (string) $descriptor['type']);

        if ($useMemo) {
            $this->memo[$memoKey] = $value;
        }

        return $value;
    }

    /**
     * Every governable key with its effective value + provenance, for the
     * governance UI / CLI / MCP read surface.
     *
     * @return list<array<string,mixed>>
     */
    public function all(string $tenantId, string $projectKey = AppSetting::WILDCARD): array
    {
        // Prefetch every governable key's rows at both the wildcard and the
        // requested project scope in ONE query, then layer + cast in memory —
        // the read surface stays O(1) queries as the registry grows (avoids the
        // earlier O(keys) N+1).
        $rows = AppSetting::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('setting_key', array_keys(AppSettingRegistry::all()))
            ->whereIn('project_key', array_unique([AppSetting::WILDCARD, $projectKey]))
            ->get();

        /** @var array<string, array<string, mixed>> $byKey [setting_key][project_key] => value_json */
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->setting_key][$row->project_key] = $row->value_json;
        }

        $out = [];
        foreach (AppSettingRegistry::all() as $key => $d) {
            $project = $byKey[$key][$projectKey] ?? null;
            $wildcard = $byKey[$key][AppSetting::WILDCARD] ?? null;
            // A tenant-scoped key never reports source=project, even if a stray
            // project row exists — mirrors effective()'s read-side scope rule.
            $hasProject = $this->allowsProjectScope($d)
                && $projectKey !== AppSetting::WILDCARD
                && $project !== null;

            $raw = $hasProject ? $project : ($wildcard ?? config((string) $d['config']));
            $source = $hasProject ? 'project' : ($wildcard !== null ? 'tenant' : 'config');

            $out[] = [
                'key' => $key,
                'label' => $d['label'],
                'type' => $d['type'],
                'scope' => $d['scope'],
                'deploy_only' => (bool) $d['deployOnly'],
                'enum' => $d['enum'] ?? null,
                'value' => $this->cast($raw, (string) $d['type']),
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

        // A tenant-scoped key must not be overridden per project — otherwise
        // provenance would report `source=project` for a key the registry says
        // never varies by project. Reject loudly (R14) rather than silently
        // accepting a no-op override.
        if (! $this->allowsProjectScope($descriptor) && $projectKey !== AppSetting::WILDCARD) {
            throw ValidationException::withMessages([
                'project_key' => ["'{$key}' is tenant-scoped and cannot be overridden per project."],
            ]);
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

    /**
     * Whether a key may be overridden per project. Only `scope=both` keys
     * layer per project; everything else is tenant-wide ('*') on read + write.
     *
     * @param  array<string,mixed>  $descriptor
     */
    private function allowsProjectScope(array $descriptor): bool
    {
        return ($descriptor['scope'] ?? 'tenant') === 'both';
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
            // Reject non-integers — a decimal like "12.5" must NOT be silently
            // truncated to 12 (R14). Accept only whole numbers (int or numeric
            // string with no fractional part).
            if (! is_numeric($value) || floor((float) $value) != (float) $value) {
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
            // FILTER_NULL_ON_FAILURE so an unrecognised string ("maybe") is
            // rejected with a 422 instead of silently coerced to false (R14).
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                throw ValidationException::withMessages(['value' => ['Value must be a boolean.']]);
            }

            return $bool;
        }

        return (string) $value;
    }
}
