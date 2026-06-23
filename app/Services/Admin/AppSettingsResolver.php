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

        // Normalise at the core — this service is callable directly, not only
        // through the (already-normalising) surfaces (empty/whitespace → '*').
        $projectKey = AppSetting::normalizeProjectKey($projectKey);

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

        // exact-project overrides tenant '*' overrides the config default —
        // skipping any override row that fails validation (corrupt/manual data).
        $projectRaw = $projectKey !== AppSetting::WILDCARD ? $rows->get($projectKey)?->value_json : null;
        $wildcardRaw = $rows->get(AppSetting::WILDCARD)?->value_json;

        [$value] = $this->resolveLayered($projectRaw, $wildcardRaw, $default, $descriptor);

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
        $projectKey = AppSetting::normalizeProjectKey($projectKey);

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
            // A tenant-scoped key never considers a project row (even a stray
            // one) — mirrors effective()'s read-side scope rule.
            $projectRaw = ($this->allowsProjectScope($d) && $projectKey !== AppSetting::WILDCARD)
                ? ($byKey[$key][$projectKey] ?? null)
                : null;
            $wildcardRaw = $byKey[$key][AppSetting::WILDCARD] ?? null;

            [$value, $source] = $this->resolveLayered($projectRaw, $wildcardRaw, config((string) $d['config']), $d);

            $out[] = [
                'key' => $key,
                'label' => $d['label'],
                'type' => $d['type'],
                'scope' => $d['scope'],
                'deploy_only' => (bool) $d['deployOnly'],
                'enum' => $d['enum'] ?? null,
                'value' => $value,
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
        // Normalise at the core — this service is callable directly, so an
        // empty/whitespace project_key must mean tenant-wide ('*'), not be
        // treated as a project override or persisted as an empty scope.
        $projectKey = AppSetting::normalizeProjectKey($projectKey);

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

        // Guard the column cap (app_settings.project_key is 120 chars) at the
        // core so EVERY write surface (HTTP + CLI) fails cleanly with a 422 /
        // user-facing message instead of a raw DB error.
        if (strlen($projectKey) > 120) {
            throw ValidationException::withMessages([
                'project_key' => ['Project key must not exceed 120 characters.'],
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

    /**
     * Pick the effective value across the precedence layers (exact-project →
     * tenant '*' → config default), SKIPPING any override row that fails
     * validation for the key's type. A corrupt/manual DB value (e.g. an
     * out-of-range int, an unknown enum) must not be silently coerced — it is
     * ignored and the next layer wins (R14). The deploy-managed config default
     * is trusted and cast leniently as the final fallback.
     *
     * @param  array<string,mixed>  $descriptor
     * @return array{0: mixed, 1: string}  [casted value, source: project|tenant|config]
     */
    private function resolveLayered(mixed $projectRaw, mixed $wildcardRaw, mixed $default, array $descriptor): array
    {
        if ($projectRaw !== null && ($v = $this->tryCoerce($projectRaw, $descriptor)) !== null) {
            return [$v, 'project'];
        }

        if ($wildcardRaw !== null && ($v = $this->tryCoerce($wildcardRaw, $descriptor)) !== null) {
            return [$v, 'tenant'];
        }

        return [$this->cast($default, (string) $descriptor['type']), 'config'];
    }

    /**
     * Validate + coerce a stored override value, or null if it is invalid for
     * the key's type (so callers can fall through to the next layer).
     *
     * @param  array<string,mixed>  $descriptor
     */
    private function tryCoerce(mixed $raw, array $descriptor): mixed
    {
        try {
            return $this->validateValue($raw, $descriptor);
        } catch (ValidationException) {
            return null;
        }
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

        // The HTTP surface accepts any JSON type for `value`. A non-scalar
        // (array/object) is never valid for any governable key — reject it up
        // front so it reliably becomes a 422 with no noisy filter_var warning
        // (R14), and a corrupt DB row falls through to the next layer.
        if (! is_scalar($value)) {
            throw ValidationException::withMessages(['value' => ['Value must be a scalar.']]);
        }

        if ($type === 'enum') {
            $options = (array) ($descriptor['enum'] ?? []);
            if (! in_array($value, $options, true)) {
                throw ValidationException::withMessages(['value' => ['Value must be one of: '.implode(', ', $options).'.']]);
            }

            return (string) $value;
        }

        if ($type === 'int') {
            // Accept ONLY a real integer or a pure-digit string. A decimal
            // ("12.5"), a float ("60.0"), or scientific notation ("60e0") are
            // rejected rather than silently truncated (R14).
            $isInt = is_int($value)
                || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1);
            if (! $isInt) {
                throw ValidationException::withMessages(['value' => ['Value must be an integer.']]);
            }
            $int = (int) (is_string($value) ? trim($value) : $value);
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

        // string (and any other type): non-scalars are already rejected above.
        return (string) $value;
    }
}
