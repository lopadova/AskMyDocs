<?php

declare(strict_types=1);

namespace App\Support\Chat;

/**
 * How much a chat session matters to its owner.
 *
 * Three levels on purpose. The Sessions sidebar sorts pinned threads
 * first, then by importance, then by recency — a five-star scale would
 * make that ordering unreadable and the badges noisy, while a boolean
 * cannot express "keep an eye on this" separately from "this is on fire".
 *
 * The stored value is the machine-readable identifier and NEVER
 * localizes (R24). There is deliberately NO label() here: the only
 * user-visible strings for this taxonomy live in the Sessions UI
 * (`SessionRow` / `SessionRowMenu`), alongside every other literal in
 * that panel. A server-side label would be a SECOND translation surface
 * for the same three words — exactly what R24 forbids — and it could not
 * serve the picker anyway, which needs labels for levels the
 * conversation does not currently have. Localizing that panel is a
 * separate, whole-panel task.
 *
 * {@see self::weight()} exists because the column is a string: ordering
 * on it directly would be ALPHABETICAL. `critical < high < normal`
 * happens to be almost right today, which is precisely the trap — it
 * breaks the day a fourth level is added. Every ordering query must
 * build its CASE from these weights instead.
 */
enum ConversationImportance: string
{
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    /** Lower sorts first. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Normal => 2,
        };
    }

    /**
     * SQL `CASE` body ordering importance by weight, built FROM the enum
     * so a new case can never silently sort last.
     *
     * Safe to interpolate: every value is a compile-time constant matching
     * /^[a-z]+$/ and no request data reaches this string (R19).
     */
    public static function orderByCaseSql(string $column = 'importance'): string
    {
        $arms = '';
        foreach (self::cases() as $case) {
            $arms .= sprintf(" WHEN '%s' THEN %d", $case->value, $case->weight());
        }

        return sprintf('CASE %s%s ELSE 99 END', $column, $arms);
    }
}
