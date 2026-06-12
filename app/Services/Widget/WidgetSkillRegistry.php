<?php

declare(strict_types=1);

namespace App\Services\Widget;

use Illuminate\Support\Facades\File;

/**
 * WidgetSkillRegistry — carica i manifest skill dal filesystem.
 *
 * Una "skill" definisce il whitelist dei tool (`tools_enabled`), le regole di
 * auto-annotazione (`auto_annotation_rules`) e le policy di default
 * (max_steps, conferme…). I manifest vivono in
 * `resources/widget/skills/{skill}/manifest.json` (port del concetto KITT §8).
 *
 * In M1 serve a `/api/widget/setup`. In M4 lo consumeranno orchestratore +
 * validator dei tool_call.
 */
class WidgetSkillRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * Ritorna il manifest della skill, o null se non esiste / non è valido.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $skill): ?array
    {
        if (! $this->isValidSkillId($skill)) {
            return null;
        }

        if (array_key_exists($skill, $this->cache)) {
            return $this->cache[$skill];
        }

        $path = $this->basePath()."/{$skill}/manifest.json";
        if (! File::exists($path)) {
            return $this->cache[$skill] = null;
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['tools_enabled']) || ! is_array($decoded['tools_enabled'])) {
            return $this->cache[$skill] = null;
        }

        return $this->cache[$skill] = $decoded;
    }

    /** Solo identificatori `name@version` sicuri come segmento di path (no traversal). */
    private function isValidSkillId(string $skill): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]*@[0-9]+$/', $skill);
    }

    private function basePath(): string
    {
        return (string) config('widget.skills_path', resource_path('widget/skills'));
    }
}
