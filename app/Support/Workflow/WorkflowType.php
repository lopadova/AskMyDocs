<?php

declare(strict_types=1);

namespace App\Support\Workflow;

/**
 * v4.7/W2 — Workflow type discriminator.
 *
 * `assistant` — free-form chat workflow. Carries `prompt_md` only.
 * `tabular`   — column-based extraction workflow. Carries `prompt_md`
 *               (general instructions) + `columns_config` (per-column
 *               prompts and format types, mirroring `tabular_reviews`).
 */
enum WorkflowType: string
{
    case Assistant = 'assistant';
    case Tabular = 'tabular';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
