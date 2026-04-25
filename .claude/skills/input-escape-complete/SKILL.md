---
name: input-escape-complete
description: Escape every meta-character for every operator — LIKE (`%`, `_`, `\\`), fnmatch (`FNM_PATHNAME` for paths), regex (`.` / `+` / `*` / `?` / grouping chars), CSV env vars (trim + filter). Partial escaping is broken escaping. Trigger when editing a controller that runs LIKE against user input, a scope/policy that calls fnmatch on glob lists, a shell/CI script that treats a host list as a regex, or a config file that splits a CSV env var.
---

# Input escape complete

## Rule

Every string passed to an operator with meta-characters must be
escaped for **every** meta-char, not just the obvious one. Partial
escaping is worse than none — it gives false confidence.

- **LIKE** (`%LIKE 'a_%\\\\b'`): escape `%`, `_`, AND `\\`. Always
  specify `ESCAPE '\\'` explicitly so every DB driver agrees on the
  escape char.
- **fnmatch()**: always pass `FNM_PATHNAME` when the input represents
  a path — otherwise `*` silently matches `/`.
- **regex literals**: a string meant as a literal substring is NOT a
  regex. In `grep -Eq` a `.` matches any char; use `grep -Fq` (fixed
  string) OR escape every meta-char.
- **CSV env vars**: never bare `explode(',', $env)`. Run through
  `array_filter(array_map('trim', explode(',', $env)))` so leading /
  trailing whitespace and trailing commas don't round into the list.

## Symptoms in a review diff

- `where('name', 'like', '%' . str_replace('%', '\\%', $q) . '%')` —
  escapes `%` but not `_`.
- `fnmatch($glob, $path)` with no flag argument.
- `grep -Eq "api.openai.com|api.anthropic.com" <<<"$line"` — `.`
  matches any char, not a literal dot.
- `config('cors.allowed_origins', explode(',', env('CORS_ALLOWED_ORIGINS', '')))`.

## How to detect in-code

```bash
# LIKE escaping half-done
rg -n "str_replace\(['\"]%['\"]" app/Http/Controllers/

# Unflagged fnmatch
rg -n 'fnmatch\([^,]+,[^,]+\)' app/

# grep -Eq on substring lists
rg -n 'grep -Eq' scripts/ .github/

# Raw explode of env CSV
rg -n "explode\(',',\s*env\(" config/
```

## Fix templates

### LIKE — escape `%`, `_`, and `\\` (PR #23 UserController)

```php
// ❌
$q = str_replace('%', '\\%', $request->input('q', ''));
$users = User::where('email', 'like', "%{$q}%")->get();

// ✅
private function escapeLike(string $raw): string
{
    return str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'],
        $raw
    );
}

// Always specify ESCAPE so every driver agrees on the escape char.
// Use whereRaw because Eloquent's where('col','like',$v) builder
// emits no ESCAPE clause and SQLite/PostgreSQL/MySQL otherwise
// disagree on the default.
$q = $this->escapeLike($request->input('q', ''));
$users = User::whereRaw("email LIKE ? ESCAPE '\\\\'", ["%{$q}%"])
    ->get();
```

### fnmatch — always `FNM_PATHNAME` on paths (PR #18 User::matchesAnyGlob)

```php
// ❌
public function matchesAnyGlob(array $globs, string $path): bool
{
    foreach ($globs as $glob) {
        if (fnmatch($glob, $path)) return true;
    }
    return false;
}

// ✅
public function matchesAnyGlob(array $globs, string $path): bool
{
    foreach ($globs as $glob) {
        if (fnmatch($glob, $path, FNM_PATHNAME | FNM_CASEFOLD)) return true;
    }
    return false;
}
```

### Regex literal — prefer fixed-string grep (PR #21 verify-e2e-real-data.sh)

```bash
# ❌ — `.` matches any char
EXTERNAL_PATTERNS=(
  'api.openai.com'
  'api.anthropic.com'
)
for p in "${EXTERNAL_PATTERNS[@]}"; do
  if printf '%s' "$content" | grep -Eq "${p}"; then ... ; fi
done

# ✅ — -F treats the pattern as a literal substring
for p in "${EXTERNAL_PATTERNS[@]}"; do
  if printf '%s' "$content" | grep -Fq "${p}"; then ... ; fi
done

# ✅ alt — if you need ERE features, escape the literal chars
p_re="${p//./\\.}"
printf '%s' "$content" | grep -Eq "${p_re}"
```

### CSV env var — trim + filter (PR #17 cors / sanctum)

```php
// ❌
'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '*')),

// ✅ — reusable helper
// config/kb.php already has:
'allowed_origins' => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))
))),

// Or extract a helper under app/Support/EnvList.php
namespace App\Support;

final class EnvList
{
    public static function csv(string $name, string $default = ''): array
    {
        $raw = (string) env($name, $default);
        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }
}

// Usage:
'allowed_origins' => \App\Support\EnvList::csv('CORS_ALLOWED_ORIGINS', '*'),
```

## Related rules

- R7 — no `@`-silenced errors. Escaping failures should NOT be hidden
  behind `@preg_match`.
- R9 — any env-var listing in a doc must describe the trim/filter
  behaviour; docs + config agree on the parsing contract.
- R21 — bad input escaping is one path to an injection; R21 covers
  the concurrency side of security invariants.

## Enforcement

- `copilot-review-anticipator` sub-agent greps for the four
  symptoms above. Currently no dedicated CI script — the false
  positive rate of "does this LIKE have a `_`?" is high enough that
  agent-assisted review is more ergonomic than a blanket gate.

## Counter-example

```php
// ❌ Every flavour of partial escaping
$q = str_replace('%', '\\%', $request->input('q'));               // _ not escaped
fnmatch($glob, $path);                                             // * matches /
grep -Eq "api.openai.com" "$line";                                 // . is regex wildcard
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', '')),   // ' localhost' with space
```
