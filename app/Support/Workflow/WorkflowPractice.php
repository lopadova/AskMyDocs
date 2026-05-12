<?php

declare(strict_types=1);

namespace App\Support\Workflow;

/**
 * v4.7/W2 — Workflow practice / vertical hint.
 *
 * Drives the suggester ranking and the FE catalogue filter. `custom`
 * keeps the door open for a tenant-supplied taxonomy without forcing
 * a schema migration.
 */
enum WorkflowPractice: string
{
    case Legal = 'legal';
    case Generic = 'generic';
    case Compliance = 'compliance';
    case Engineering = 'engineering';
    case Sales = 'sales';
    case Support = 'support';
    case Custom = 'custom';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
