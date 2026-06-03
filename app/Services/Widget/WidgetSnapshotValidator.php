<?php

declare(strict_types=1);

namespace App\Services\Widget;

use InvalidArgumentException;

/**
 * WidgetSnapshotValidator — fa rispettare i cap dello snapshot (spec §4.5)
 * e ri-sanitizza i testi server-side (M5.7 — il BE non si fida del FE).
 *
 * I cap proteggono il prompt-context del modello (token budget) e sono una
 * difesa in profondità contro snapshot abnormi inviati da un client
 * compromesso. Le violazioni lanciano InvalidArgumentException → il
 * controller risponde 422 PRIMA di chiamare l'LLM (R14: niente 200 muto).
 *
 * M5.7 aggiunge sanitizeSnapshot(): ripulisce tutti i campi testuali
 * dal markup/fence/zwc che un client compromesso potrebbe iniettare.
 * Specchia la logica del FE sanitizeText (spec §3) ma lato server.
 */
final class WidgetSnapshotValidator
{
    private const CAPS = [
        'regions' => 50,
        'fields' => 500,
        'actions' => 200,
        'messages' => 50,
        'locales_available' => 20,
    ];

    private const OUTLINE_CAPS = [
        'headings' => 30,
        'buttons_unannotated' => 80,
        'inputs_unannotated' => 100,
    ];

    /** Campi testuali da sanitizzare ricorsivamente in ogni item. */
    private const TEXT_KEYS = ['label', 'value', 'text', 'title', 'placeholder', 'name', 'summary', 'content', 'verb'];

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @throws InvalidArgumentException quando un cap è superato.
     */
    public function assertWithinCaps(array $snapshot): void
    {
        foreach (self::CAPS as $key => $cap) {
            $this->assertCount($snapshot[$key] ?? [], $cap, $key);
        }

        $outline = is_array($snapshot['page_outline'] ?? null) ? $snapshot['page_outline'] : [];
        foreach (self::OUTLINE_CAPS as $key => $cap) {
            $this->assertCount($outline[$key] ?? [], $cap, "page_outline.{$key}");
        }
    }

    /**
     * Ri-sanitizza i testi dello snapshot lato server (M5.7).
     *
     * Il FE fa già sanitizeText (strip <>, ```, zero-width chars), ma il BE
     * non si fida. Applica la stessa logica ricorsivamente sui campi testuali
     * conosciuti in regions, fields, actions, messages, page, page_outline.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>  snapshot sanitarizzato
     */
    public function sanitizeSnapshot(array $snapshot): array
    {
        // page (url + title)
        if (is_array($snapshot['page'] ?? null)) {
            $snapshot['page'] = $this->sanitizeItemTexts($snapshot['page']);
        }

        // Collections: regions, fields, actions, messages
        foreach (['regions', 'fields', 'actions', 'messages'] as $key) {
            if (is_array($snapshot[$key] ?? null)) {
                $snapshot[$key] = array_map(
                    fn (mixed $item) => is_array($item) ? $this->sanitizeItemTexts($item) : $item,
                    $snapshot[$key],
                );
            }
        }

        // page_outline
        if (is_array($snapshot['page_outline'] ?? null)) {
            $snapshot['page_outline'] = $this->sanitizeOutline($snapshot['page_outline']);
        }

        return $snapshot;
    }

    /**
     * M5.8 — Guard BE: i campi data-kitt-sensitive DEVONO avere value:null.
     *
     * Il FE già setta value: null per i field con sensitive:true, ma il BE
     * non si fida. Se un client compromesso invia un campo sensitive con
     * value non-null, questo metodo forza value a null (difesa in profondità).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>  snapshot con sensitive fields sanati
     */
    public function enforceSensitiveNull(array $snapshot): array
    {
        if (is_array($snapshot['fields'] ?? null)) {
            $snapshot['fields'] = array_map(function (mixed $field): mixed {
                if (! is_array($field)) {
                    return $field;
                }

                // Se il campo è marcato sensitive e ha un value non-null, forza a null
                if (! empty($field['sensitive']) && array_key_exists('value', $field) && $field['value'] !== null) {
                    $field['value'] = null;
                }

                return $field;
            }, $snapshot['fields']);
        }

        // Controlla anche regions che potrebbero contenere sotto-campi sensitive
        if (is_array($snapshot['regions'] ?? null)) {
            $snapshot['regions'] = array_map(function (mixed $region): mixed {
                if (! is_array($region)) {
                    return $region;
                }

                if (is_array($region['fields'] ?? null)) {
                    $region['fields'] = array_map(function (mixed $field): mixed {
                        if (! is_array($field)) {
                            return $field;
                        }
                        if (! empty($field['sensitive']) && array_key_exists('value', $field) && $field['value'] !== null) {
                            $field['value'] = null;
                        }

                        return $field;
                    }, $region['fields']);
                }

                return $region;
            }, $snapshot['regions']);
        }

        return $snapshot;
    }

    /**
     * Sanitizza una stringa testuale: niente markup, fence, zero-width.
     * Port server-side di sanitizeText (FE spec §3).
     */
    public function sanitizeText(string $input): string
    {
        $str = str_replace(['<', '>'], ' ', $input);    // niente markup
        $str = str_replace('```', '   ', $str);          // niente code fence
        $str = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u', '', $str); // zero-width
        $str = preg_replace('/\s+/u', ' ', $str);       // collapse whitespace

        return trim($str);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function sanitizeItemTexts(array $item): array
    {
        foreach (self::TEXT_KEYS as $key) {
            if (isset($item[$key]) && is_string($item[$key])) {
                $item[$key] = $this->sanitizeText($item[$key]);
            }
        }

        // Ricorsione su sotto-array (es. fields dentro regions)
        foreach ($item as $key => $value) {
            if (is_array($value) && ! in_array($key, self::TEXT_KEYS, true)) {
                $item[$key] = array_map(
                    fn (mixed $v) => is_array($v) ? $this->sanitizeItemTexts($v) : $v,
                    $value,
                );
            }
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $outline
     * @return array<string, mixed>
     */
    private function sanitizeOutline(array $outline): array
    {
        foreach ($outline as $key => $items) {
            if (is_array($items)) {
                $outline[$key] = array_map(
                    fn (mixed $item) => is_array($item) ? $this->sanitizeItemTexts($item) : $item,
                    $items,
                );
            }
        }

        return $outline;
    }

    private function assertCount(mixed $value, int $cap, string $label): void
    {
        if (is_array($value) && count($value) > $cap) {
            throw new InvalidArgumentException(
                "Snapshot field '{$label}' exceeds the cap of {$cap} (got ".count($value).').'
            );
        }
    }
}
