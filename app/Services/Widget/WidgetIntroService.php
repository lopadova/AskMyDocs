<?php

declare(strict_types=1);

namespace App\Services\Widget;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical contract for the widget's pre-conversation introduction card.
 * Content is structured and rendered as text nodes; arbitrary HTML is never
 * accepted. Keep defaults and limits aligned with frontend/src/widget/ui/intro.ts.
 */
final class WidgetIntroService
{
    private const VARIANTS = ['compact', 'card', 'hero'];

    private const ICONS = ['sparkles', 'chat', 'search', 'help', 'none'];

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'enabled' => false,
            'variant' => 'card',
            'eyebrow' => '',
            'title' => '',
            'subtitle' => '',
            'body' => '',
            'imageUrl' => '',
            'imageAlt' => '',
            'icon' => 'sparkles',
            'bullets' => [],
            'suggestions' => [],
            'dismissible' => true,
            'hideAfterFirstMessage' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function rules(string $prefix = 'intro'): array
    {
        return [
            $prefix => ['sometimes', 'array'],
            "{$prefix}.enabled" => ['sometimes', 'boolean'],
            "{$prefix}.variant" => ['sometimes', 'string', Rule::in(self::VARIANTS)],
            "{$prefix}.eyebrow" => ['sometimes', 'nullable', 'string', 'max:60'],
            "{$prefix}.title" => ['sometimes', 'nullable', 'string', 'max:120'],
            "{$prefix}.subtitle" => ['sometimes', 'nullable', 'string', 'max:180'],
            "{$prefix}.body" => ['sometimes', 'nullable', 'string', 'max:600'],
            "{$prefix}.imageUrl" => ['sometimes', 'nullable', 'string', 'url', 'max:500', 'starts_with:https://'],
            "{$prefix}.imageAlt" => ['sometimes', 'nullable', 'string', 'max:160'],
            "{$prefix}.icon" => ['sometimes', 'string', Rule::in(self::ICONS)],
            "{$prefix}.bullets" => ['sometimes', 'array', 'max:4'],
            "{$prefix}.bullets.*" => ['string', 'max:160'],
            "{$prefix}.suggestions" => ['sometimes', 'array', 'max:4'],
            "{$prefix}.suggestions.*" => ['array:label,prompt'],
            "{$prefix}.suggestions.*.label" => ['required', 'string', 'max:80'],
            "{$prefix}.suggestions.*.prompt" => ['required', 'string', 'max:500'],
            "{$prefix}.dismissible" => ['sometimes', 'boolean'],
            "{$prefix}.hideAfterFirstMessage" => ['sometimes', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function sanitize(array $input): array
    {
        $defaults = $this->defaults();

        $bullets = [];
        foreach (array_slice(is_array($input['bullets'] ?? null) ? $input['bullets'] : [], 0, 4) as $bullet) {
            $text = $this->text($bullet, 160);
            if ($text !== '') {
                $bullets[] = $text;
            }
        }

        $suggestions = [];
        foreach (array_slice(is_array($input['suggestions'] ?? null) ? $input['suggestions'] : [], 0, 4) as $suggestion) {
            if (! is_array($suggestion)) {
                continue;
            }
            $label = $this->text($suggestion['label'] ?? null, 80);
            $prompt = $this->text($suggestion['prompt'] ?? null, 500, true);
            if ($label !== '' && $prompt !== '') {
                $suggestions[] = ['label' => $label, 'prompt' => $prompt];
            }
        }

        return [
            'enabled' => is_bool($input['enabled'] ?? null) ? $input['enabled'] : $defaults['enabled'],
            'variant' => $this->enum($input['variant'] ?? null, self::VARIANTS, $defaults['variant']),
            'eyebrow' => $this->text($input['eyebrow'] ?? null, 60),
            'title' => $this->text($input['title'] ?? null, 120),
            'subtitle' => $this->text($input['subtitle'] ?? null, 180),
            'body' => $this->text($input['body'] ?? null, 600, true),
            'imageUrl' => $this->url($input['imageUrl'] ?? null),
            'imageAlt' => $this->text($input['imageAlt'] ?? null, 160),
            'icon' => $this->enum($input['icon'] ?? null, self::ICONS, $defaults['icon']),
            'bullets' => $bullets,
            'suggestions' => $suggestions,
            'dismissible' => is_bool($input['dismissible'] ?? null) ? $input['dismissible'] : $defaults['dismissible'],
            'hideAfterFirstMessage' => is_bool($input['hideAfterFirstMessage'] ?? null)
                ? $input['hideAfterFirstMessage']
                : $defaults['hideAfterFirstMessage'],
        ];
    }

    /** @param array<string, mixed>|null $stored @return array<string, mixed> */
    public function resolve(?array $stored): array
    {
        return $this->sanitize(array_replace($this->defaults(), is_array($stored) ? $stored : []));
    }

    /** @param array<string, mixed>|null $stored @param array<string, mixed> $patch @return array<string, mixed> */
    public function patch(?array $stored, array $patch): array
    {
        return $this->sanitize(array_replace($this->resolve($stored), $patch));
    }

    /** @param array<string, mixed> $intro */
    public function assertUsable(array $intro, string $prefix = 'intro'): void
    {
        if (($intro['enabled'] ?? false) === true && ($intro['title'] ?? '') === '') {
            throw ValidationException::withMessages([
                "{$prefix}.title" => ['A title is required when the widget introduction is enabled.'],
            ]);
        }
        if (($intro['imageUrl'] ?? '') !== '' && ($intro['imageAlt'] ?? '') === '') {
            throw ValidationException::withMessages([
                "{$prefix}.imageAlt" => ['Alternative text is required when an introduction image is configured.'],
            ]);
        }
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function text(mixed $value, int $max, bool $multiline = false): string
    {
        if (! is_string($value)) {
            return '';
        }
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        $clean = preg_replace($pattern, '', trim($value)) ?? '';

        return mb_substr($clean, 0, $max);
    }

    private function url(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        $value = trim($value);
        if (mb_strlen($value) > 500 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        if (! str_starts_with(strtolower($value), 'https://') || preg_match('/["\'\(\)<>\s\\\\]/', $value) === 1) {
            return '';
        }

        return 'https://'.substr($value, strlen('https://'));
    }
}
