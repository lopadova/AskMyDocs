<?php

declare(strict_types=1);

namespace App\Services\Widget;

use Illuminate\Validation\Rule;

/**
 * WidgetThemeService — fonte unica del tema grafico del widget KITT.
 *
 * Tre responsabilità, una sola definizione del contratto:
 *   - {@see defaults()}  i valori di default canonici (rispecchiano i fallback
 *     delle CSS var del widget e il `DEFAULT_THEME` TS — R9 docs-match-code);
 *   - {@see rules()}     le regole di validazione annidate `theme.*` da fondere
 *     nel `validate()` dei controller admin → 422 su input non valido (R14);
 *   - {@see sanitize()}  la normalizzazione AUTORITATIVA (clamp/allowlist/hex/
 *     url-https) applicata prima di persistere e prima di servire — difesa in
 *     profondità contro la CSS injection (R19: il tema confluisce in una
 *     stringa <style> nello Shadow DOM del sito ospite).
 *
 * Il valore persistito su `widget_keys.theme_config` è SEMPRE l'array completo
 * sanificato; {@see resolve()} fonde lo stored sui default per `/setup`.
 */
final class WidgetThemeService
{
    /**
     * Font ammessi: chiave stabile → stack CSS sicuro. Non si interpola mai lo
     * stack grezzo dell'utente (R19). Tenere in sync con FONT_STACKS (TS).
     *
     * @var array<string,string>
     */
    private const FONTS = [
        'system' => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        'inter' => "Inter, system-ui, -apple-system, sans-serif",
        'roboto' => "Roboto, system-ui, -apple-system, sans-serif",
        'georgia' => "Georgia, 'Times New Roman', serif",
        'mono' => "'SFMono-Regular', Menlo, Consolas, monospace",
    ];

    /**
     * Modalità di resa del widget:
     *   - `helper`  launcher flottante (position:fixed) → pannello a comparsa (kitt);
     *   - `inline`  blocco chat che riempie il container ospite (chat legata a pagina).
     * Tenere in sync con WIDGET_MODES (TS).
     *
     * @var list<string>
     */
    private const MODES = ['helper', 'inline'];

    /** @var list<string> */
    private const LAUNCHER_SIDES = ['right', 'left'];

    /** @var list<string> */
    private const LAUNCHER_SHAPES = ['pill', 'rounded', 'circle'];

    /** @var list<string> */
    private const LAUNCHER_ICONS = ['chat', 'sparkles', 'help', 'none'];

    /** Campi colore (solo hex). @var list<string> */
    private const COLOR_KEYS = [
        'accent', 'background', 'foreground', 'muted', 'border',
        'headerBackground', 'headerForeground',
        'launcherBackground', 'launcherForeground',
        'userBubbleBackground', 'userBubbleForeground',
        'assistantBubbleBackground', 'assistantBubbleForeground',
    ];

    /** Hex #rgb / #rrggbb / #rrggbbaa. */
    private const HEX_RE = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    /**
     * Tema di default canonico. Rispecchia i fallback delle CSS var del widget
     * (frontend/src/widget/ui/styles.ts → DEFAULT_THEME). R9: se cambi un
     * valore qui, aggiornalo anche lì e nel fallback `var(--x, …)`.
     *
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            // Modalità (layout): helper = launcher flottante, inline = blocco a pagina.
            'mode' => 'helper',
            // Colori
            'accent' => '#2563eb',
            'background' => '#ffffff',
            'foreground' => '#1f2937',
            'muted' => '#6b7280',
            'border' => '#e5e7eb',
            'headerBackground' => '#2563eb',
            'headerForeground' => '#ffffff',
            'launcherBackground' => '#2563eb',
            'launcherForeground' => '#ffffff',
            'userBubbleBackground' => '#2563eb',
            'userBubbleForeground' => '#ffffff',
            'assistantBubbleBackground' => '#f3f4f6',
            'assistantBubbleForeground' => '#1f2937',
            // Tipografia
            'fontFamily' => 'system',
            'fontSize' => 14,
            // Launcher
            'launcherSide' => 'right',
            'launcherShape' => 'pill',
            'launcherLabel' => '',
            'launcherIcon' => 'chat',
            'launcherIconUrl' => '',
            // Pannello
            'panelWidth' => 380,
            'panelHeight' => 560,
            'panelRadius' => 14,
            'panelTitle' => '',
            'headerLogoUrl' => '',
        ];
    }

    /**
     * Regole di validazione per il blocco `theme` annidato. `sometimes` ovunque
     * → il tema è opzionale e accetta update parziali. Forma array così i `|`
     * nelle regex non vengono spezzati.
     *
     * @return array<string,mixed>
     */
    public function rules(string $prefix = 'theme'): array
    {
        $rules = [
            $prefix => ['sometimes', 'array'],
            "{$prefix}.mode" => ['sometimes', 'string', Rule::in(self::MODES)],
            "{$prefix}.fontFamily" => ['sometimes', 'string', Rule::in(array_keys(self::FONTS))],
            "{$prefix}.fontSize" => ['sometimes', 'integer', 'min:12', 'max:18'],
            "{$prefix}.launcherSide" => ['sometimes', 'string', Rule::in(self::LAUNCHER_SIDES)],
            "{$prefix}.launcherShape" => ['sometimes', 'string', Rule::in(self::LAUNCHER_SHAPES)],
            "{$prefix}.launcherIcon" => ['sometimes', 'string', Rule::in(self::LAUNCHER_ICONS)],
            "{$prefix}.launcherLabel" => ['sometimes', 'nullable', 'string', 'max:60'],
            "{$prefix}.panelTitle" => ['sometimes', 'nullable', 'string', 'max:60'],
            "{$prefix}.launcherIconUrl" => ['sometimes', 'nullable', 'string', 'url', 'max:500', 'starts_with:https://'],
            "{$prefix}.headerLogoUrl" => ['sometimes', 'nullable', 'string', 'url', 'max:500', 'starts_with:https://'],
            "{$prefix}.panelWidth" => ['sometimes', 'integer', 'min:320', 'max:480'],
            "{$prefix}.panelHeight" => ['sometimes', 'integer', 'min:420', 'max:680'],
            "{$prefix}.panelRadius" => ['sometimes', 'integer', 'min:0', 'max:24'],
        ];

        foreach (self::COLOR_KEYS as $key) {
            $rules["{$prefix}.{$key}"] = ['sometimes', 'string', 'regex:'.self::HEX_RE];
        }

        return $rules;
    }

    /**
     * Normalizzazione autoritativa: fonde i default e valida ogni campo per
     * tipo. Qualunque valore non valido degrada al default (mai propagato in CSS).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function sanitize(array $input): array
    {
        $d = $this->defaults();
        $out = $d;

        foreach (self::COLOR_KEYS as $key) {
            $out[$key] = $this->color($input[$key] ?? null, $d[$key]);
        }

        $out['mode'] = $this->enum($input['mode'] ?? null, self::MODES, $d['mode']);
        $out['fontFamily'] = $this->enum($input['fontFamily'] ?? null, array_keys(self::FONTS), $d['fontFamily']);
        $out['fontSize'] = $this->int($input['fontSize'] ?? null, 12, 18, $d['fontSize']);
        $out['launcherSide'] = $this->enum($input['launcherSide'] ?? null, self::LAUNCHER_SIDES, $d['launcherSide']);
        $out['launcherShape'] = $this->enum($input['launcherShape'] ?? null, self::LAUNCHER_SHAPES, $d['launcherShape']);
        $out['launcherIcon'] = $this->enum($input['launcherIcon'] ?? null, self::LAUNCHER_ICONS, $d['launcherIcon']);
        $out['launcherLabel'] = $this->text($input['launcherLabel'] ?? null, 60);
        $out['panelTitle'] = $this->text($input['panelTitle'] ?? null, 60);
        $out['launcherIconUrl'] = $this->url($input['launcherIconUrl'] ?? null);
        $out['headerLogoUrl'] = $this->url($input['headerLogoUrl'] ?? null);
        $out['panelWidth'] = $this->int($input['panelWidth'] ?? null, 320, 480, $d['panelWidth']);
        $out['panelHeight'] = $this->int($input['panelHeight'] ?? null, 420, 680, $d['panelHeight']);
        $out['panelRadius'] = $this->int($input['panelRadius'] ?? null, 0, 24, $d['panelRadius']);

        return $out;
    }

    /**
     * Tema effettivo da servire: stored sanificato sui default. `null` → default.
     *
     * @param  array<string,mixed>|null  $stored
     * @return array<string,mixed>
     */
    public function resolve(?array $stored): array
    {
        return $this->sanitize(is_array($stored) ? $stored : []);
    }

    private function color(mixed $value, string $default): string
    {
        return is_string($value) && preg_match(self::HEX_RE, $value) === 1
            ? strtolower($value)
            : $default;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enum(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function int(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function text(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            return '';
        }

        // Niente caratteri di controllo nelle label (finiscono in textContent).
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value)) ?? '';

        return mb_substr($clean, 0, $max);
    }

    /**
     * URL immagine: solo https, senza meta-caratteri che possano evadere da
     * `url("…")` in CSS o da un attributo src (R19). Altrimenti stringa vuota.
     */
    private function url(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        if (! str_starts_with(strtolower($value), 'https://')) {
            return '';
        }
        // Caratteri che romperebbero url("…") / src="…".
        if (preg_match('/["\'\(\)<>\s\\\\]/', $value) === 1) {
            return '';
        }

        return $value;
    }
}
