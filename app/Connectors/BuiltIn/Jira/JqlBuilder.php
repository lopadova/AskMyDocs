<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Jira;

use Carbon\CarbonInterface;

/**
 * v4.5/W6 — Fluent builder for safe JQL (Jira Query Language) strings.
 *
 * JQL has a small set of meta-characters that must be escaped to keep
 * user-supplied values from breaking the parser (or worse, smuggling
 * extra clauses into the query). The two characters Jira's JQL grammar
 * documents as needing escape inside quoted strings are:
 *
 *   - the backslash (`\\`) — escape character itself, MUST be doubled
 *     first so subsequent rules don't double-escape it.
 *   - the single quote (`'`) — single-quoted strings are the safer
 *     literal shape (double-quotes inside JQL also need escaping but
 *     interact with HTTP form-encoding less predictably).
 *
 * Reference: Jira JQL "Quoted strings and reserved characters" — see
 * https://support.atlassian.com/jira-software-cloud/docs/advanced-search-reference-jql-fields/
 *
 * R19 (input-escape-complete): we escape `\\` BEFORE `'` deliberately
 * so a literal backslash-single-quote pair survives the round-trip
 * without producing a half-escaped artefact.
 *
 * Date format: JQL uses `"YYYY-MM-DD HH:mm"` (Jira-specific) — NOT
 * ISO-8601 with `Z`. Passing an ISO-8601 timestamp returns 400 from
 * the search endpoint. {@see updatedSince()} formats Carbon
 * instances into the wire shape automatically.
 *
 * The builder is immutable per the fluent-with-clone pattern so each
 * method returns a fresh instance — call sites can branch their
 * queries without worrying about side effects on the parent builder.
 */
final class JqlBuilder
{
    /** @var list<string> */
    private array $clauses = [];

    private ?string $orderBy = null;

    private string $orderDirection = 'DESC';

    private function __construct(?string $projectKey = null)
    {
        if ($projectKey !== null) {
            $this->clauses[] = sprintf('project = "%s"', self::escapeValue($projectKey));
        }
    }

    /**
     * Start a JQL build scoped to a single project. Most production
     * queries are project-scoped — Jira workspaces with many projects
     * frequently misroute global JQL.
     */
    public static function for(string $projectKey): self
    {
        return new self($projectKey);
    }

    /**
     * Start an un-scoped JQL build. Use when you really need a
     * workspace-wide query (rare; tenants with hundreds of projects
     * pay a meaningful latency cost). Most callers want {@see for()}.
     */
    public static function any(): self
    {
        return new self(null);
    }

    /**
     * Filter to issues updated AT OR AFTER `$since`. Jira's `updated >=`
     * is inclusive of the bound — same semantics as Confluence's CQL
     * `lastModified > "..."` (but JQL uses `>=`, not `>`). The wire
     * date format is `"YYYY-MM-DD HH:mm"` — UTC, minute precision.
     */
    public function updatedSince(CarbonInterface $since): self
    {
        $clone = clone $this;
        $clone->clauses[] = sprintf(
            'updated >= "%s"',
            $since->copy()->utc()->format('Y-m-d H:i'),
        );

        return $clone;
    }

    /**
     * Add a status filter. `$operator` accepts `=`, `!=`, `in`, `not in`.
     * For `in` / `not in`, pass an array as `$value`; otherwise a string.
     *
     * @param  string|list<string>  $value
     */
    public function status(string $operator, string|array $value): self
    {
        return $this->whereField('status', $operator, $value);
    }

    /**
     * Generic field filter. Use for fields the builder doesn't expose
     * as first-class helpers (assignee, labels, etc.).
     *
     * @param  string|list<string>  $value
     */
    public function whereField(string $field, string $operator, string|array $value): self
    {
        $cleanField = $this->validateField($field);
        $op = strtolower(trim($operator));

        $rendered = match ($op) {
            '=', '!=' => $this->renderScalarOp($cleanField, $op, $value),
            'in', 'not in' => $this->renderListOp($cleanField, $op, $value),
            default => throw new \InvalidArgumentException(
                "Unsupported JQL operator: {$operator}. Expected one of: =, !=, in, not in."
            ),
        };

        $clone = clone $this;
        $clone->clauses[] = $rendered;

        return $clone;
    }

    /**
     * Sort the result set. Used internally by `syncIncremental` so each
     * batch is ordered consistently for paginated fetch.
     */
    public function orderBy(string $field, string $direction = 'DESC'): self
    {
        $clone = clone $this;
        $clone->orderBy = $this->validateField($field);
        $dir = strtoupper(trim($direction));
        $clone->orderDirection = $dir === 'ASC' ? 'ASC' : 'DESC';

        return $clone;
    }

    /**
     * Render the final JQL string.
     */
    public function build(): string
    {
        $jql = implode(' AND ', $this->clauses);

        if ($this->orderBy !== null) {
            $jql = ($jql === '' ? '' : $jql.' ').'ORDER BY '.$this->orderBy.' '.$this->orderDirection;
        }

        return $jql;
    }

    public function __toString(): string
    {
        return $this->build();
    }

    /**
     * @param  string|list<string>  $value
     */
    private function renderScalarOp(string $field, string $op, string|array $value): string
    {
        if (is_array($value)) {
            throw new \InvalidArgumentException(
                "JQL operator '{$op}' expects a scalar value, got an array. Use 'in' / 'not in' for lists."
            );
        }

        return sprintf('%s %s "%s"', $field, $op, self::escapeValue($value));
    }

    /**
     * @param  string|list<string>  $value
     */
    private function renderListOp(string $field, string $op, string|array $value): string
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if ($value === []) {
            throw new \InvalidArgumentException(
                "JQL operator '{$op}' requires at least one value."
            );
        }

        $quoted = array_map(
            static fn (string $v): string => '"'.self::escapeValue($v).'"',
            $value,
        );

        return sprintf('%s %s (%s)', $field, strtoupper($op), implode(', ', $quoted));
    }

    /**
     * Field names are NOT user-supplied in production usage (the
     * connector picks them from a small static set). Reject anything
     * outside the conservative `[A-Za-z][A-Za-z0-9_.-]*` shape so a
     * future bug that pipes a user-supplied field-name into the builder
     * can't smuggle an operator.
     */
    private function validateField(string $field): string
    {
        $trimmed = trim($field);
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_.\-]*$/', $trimmed)) {
            throw new \InvalidArgumentException(
                "Invalid JQL field name: '{$field}'. Expected `[A-Za-z][A-Za-z0-9_.-]*`."
            );
        }

        return $trimmed;
    }

    /**
     * Escape a user-supplied value for embedding inside a JQL
     * double-quoted string. R19: escape the backslash FIRST so the
     * subsequent rules don't compound-escape it.
     *
     * Multi-byte UTF-8 is passed through verbatim — JQL accepts
     * Unicode in quoted strings.
     */
    public static function escapeValue(string $value): string
    {
        // Backslash first (R19).
        $value = str_replace('\\', '\\\\', $value);
        // Double-quote inside double-quoted string.
        $value = str_replace('"', '\\"', $value);
        // Single quote — Jira docs treat it as reserved in some
        // contexts; defensive escape leaves the round-trip stable.
        $value = str_replace("'", "\\'", $value);

        return $value;
    }
}
