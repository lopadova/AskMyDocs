<?php

declare(strict_types=1);

namespace App\Services\Widget;

/**
 * WidgetToolValidator — valida una tool_call emessa dal modello PRIMA di
 * rimandarla al FE per l'esecuzione (port spec §5.4).
 *
 * Regole:
 *   1. il tool è nel whitelist della skill (`enabled`) ed è definito nel catalogo;
 *   2. se il tool richiede `field`, quel field esiste nello snapshot
 *      (fields[].name oppure page_outline.inputs_unannotated[].name|testid);
 *   3. se richiede `target`, quel target esiste (actions[].verb oppure
 *      page_outline.buttons_unannotated[].id|testid|text);
 *   4. `navigate_to` ammette solo URL entro l'allowlist (di norma same-origin);
 *   5. `set_locale`: il locale deve essere in locales_available (se presente);
 *   6. `goto_step`: lo step deve esistere in regions/steps dello snapshot;
 *
 * Il modello NON può inventare nomi: agisce solo su ciò che è nello snapshot.
 * Lo snapshot resta la sorgente di verità (spec §15).
 */
final class WidgetToolValidator
{
    public function __construct(private readonly WidgetToolCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $enabled
     * @param  list<string>  $navigateAllowlist  origini ammesse per navigate_to
     * @return array{ok: bool, error: ?string}
     */
    public function validate(string $tool, array $args, array $snapshot, array $enabled, array $navigateAllowlist = []): array
    {
        if (! in_array($tool, $enabled, true) || ! $this->catalog->isDefined($tool)) {
            return $this->fail("Tool '{$tool}' is not enabled for this skill.");
        }

        $def = $this->catalog->definition($tool);
        $needs = $def['needs'] ?? [];

        if (in_array('field', $needs, true)) {
            $field = (string) ($args['field'] ?? '');
            if ($field === '' || ! $this->snapshotHasField($snapshot, $field)) {
                return $this->fail("Field '{$field}' does not exist in the current snapshot.");
            }
        }

        if (in_array('target', $needs, true)) {
            $target = (string) ($args['target'] ?? '');
            if ($target === '' || ! $this->snapshotHasTarget($snapshot, $target)) {
                return $this->fail("Target '{$target}' does not exist in the current snapshot.");
            }
        }

        if ($tool === 'navigate_to') {
            $url = (string) ($args['url'] ?? '');
            if (! $this->navigateAllowed($url, $navigateAllowlist)) {
                return $this->fail("Navigation to '{$url}' is not allowed (outside the navigation allowlist).");
            }
        }

        // M4: set_locale deve essere in locales_available dello snapshot (spec §5.4 regola 6)
        if ($tool === 'set_locale') {
            $locale = (string) ($args['locale'] ?? '');
            if ($locale === '') {
                return $this->fail("Tool 'set_locale' requires a non-empty 'locale' argument.");
            }
            $available = $this->arr($snapshot, 'locales_available');
            if ($available !== [] && ! in_array($locale, $available, true)) {
                return $this->fail("Locale '{$locale}' is not in locales_available: " . implode(', ', $available));
            }
        }

        // M4: goto_step deve avere uno step esistente nello snapshot (regions[] o steps[])
        if ($tool === 'goto_step') {
            $step = (string) ($args['step'] ?? '');
            if ($step === '') {
                return $this->fail("Tool 'goto_step' requires a non-empty 'step' argument.");
            }
            if (! $this->snapshotHasStep($snapshot, $step)) {
                return $this->fail("Step '{$step}' does not exist in the current snapshot.");
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHasField(array $snapshot, string $field): bool
    {
        foreach ($this->arr($snapshot, 'fields') as $f) {
            if (is_array($f) && (string) ($f['name'] ?? '') === $field) {
                return true;
            }
        }

        foreach ($this->outlineArr($snapshot, 'inputs_unannotated') as $i) {
            if (! is_array($i)) {
                continue;
            }
            if ((string) ($i['name'] ?? '') === $field || (string) ($i['testid'] ?? '') === $field) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHasTarget(array $snapshot, string $target): bool
    {
        foreach ($this->arr($snapshot, 'actions') as $a) {
            if (is_array($a) && (string) ($a['verb'] ?? '') === $target) {
                return true;
            }
        }

        foreach ($this->outlineArr($snapshot, 'buttons_unannotated') as $b) {
            if (! is_array($b)) {
                continue;
            }
            foreach (['id', 'testid', 'text'] as $k) {
                if ((string) ($b[$k] ?? '') === $target) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cerca uno step nello snapshot. Per spec §4.1 gli step vivono dentro
     * regions[].steps[].id; per retro-compatibilità cerchiamo anche
     * regions[].id (alcune pagine semplici usano la region come step) e
     * un eventuale top-level steps[].
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHasStep(array $snapshot, string $step): bool
    {
        // 1. Cerca in regions[].steps[].id (spec §4.1 — il caso principale)
        foreach ($this->arr($snapshot, 'regions') as $r) {
            if (! is_array($r)) {
                continue;
            }
            foreach ($this->arr($r, 'steps') as $s) {
                if (is_array($s) && (string) ($s['id'] ?? '') === $step) {
                    return true;
                }
            }
        }

        // 2. Fallback: region id come step (pagine semplici senza steps[])
        foreach ($this->arr($snapshot, 'regions') as $r) {
            if (is_array($r) && (string) ($r['id'] ?? '') === $step) {
                return true;
            }
        }

        // 3. Fallback: top-level steps[] (non in spec, ma difensivo)
        foreach ($this->arr($snapshot, 'steps') as $s) {
            if (is_array($s) && (string) ($s['id'] ?? '') === $step) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowlist
     */
    private function navigateAllowed(string $url, array $allowlist): bool
    {
        if ($url === '') {
            return false;
        }

        // M5.11 (R19) — block dangerous schemes: javascript:, data:, vbscript:.
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
            return false;
        }

        // #9 (R19) — i browser normalizzano '\' → '/' per gli schemi speciali
        // (http/https), quindi '/\evil.com' e '/%5cevil.com' diventano
        // '//evil.com' = navigazione protocol-relative CROSS-ORIGIN. Normalizziamo
        // backslash + percent-encoding PRIMA dei controlli, così il ramo
        // same-origin non li lascia passare come path relativi (open redirect).
        $probe = str_replace(['\\', '%5c', '%5C'], '/', $url);

        // M5.11 (R19) — block protocol-relative URLs ("//host/path").
        if (str_starts_with($probe, '//')) {
            return false;
        }

        // Path relativo same-origin (starts with single /, dopo normalizzazione).
        if (str_starts_with($probe, '/')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return false;
        }

        // M5.11 (R19) — only http/https schemes allowed after parse.
        if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        $port = parse_url($url, PHP_URL_PORT);
        $origin = strtolower($scheme.'://'.$host.($port ? ':'.$port : ''));

        foreach ($allowlist as $allowed) {
            if (rtrim(strtolower($allowed), '/') === $origin) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, mixed>
     */
    private function arr(array $snapshot, string $key): array
    {
        return is_array($snapshot[$key] ?? null) ? $snapshot[$key] : [];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, mixed>
     */
    private function outlineArr(array $snapshot, string $key): array
    {
        $outline = is_array($snapshot['page_outline'] ?? null) ? $snapshot['page_outline'] : [];

        return is_array($outline[$key] ?? null) ? $outline[$key] : [];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
