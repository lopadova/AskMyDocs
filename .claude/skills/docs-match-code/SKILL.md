---
name: docs-match-code
description: Schema, signature, env-var, and config snippets in CLAUDE.md, .github/copilot-instructions.md, README.md and SKILL.md files must match the real code. Verify against migrations, models, config files, and Artisan signatures before merging. Trigger when editing any documentation file that quotes column names, env vars, command flags, route paths, or config keys; or when changing a migration / model / command / config / route in a way that any of those docs reference.
---

# Docs match code

## Rule

Anything in a doc that names a real artifact — a column, an env var, a
config key, a command flag, a route, a signature — must be **verified
against the source** before commit. Stale docs are worse than missing docs:
they lie with authority and survive grep.

Specifically, before editing:

- A schema description → open the matching migration in
  `database/migrations/` (and the test mirror under
  `tests/database/migrations/`) and copy the column names verbatim.
- An env var listing → open `.env.example` AND `config/<area>.php` and
  cross-check the key name + default.
- A command signature → run `php artisan <command> --help` (or read
  `protected $signature` in the command class).
- An API route → open `routes/api.php` / `routes/web.php`.
- A non-trivial code snippet → load the referenced class and confirm the
  method exists with that signature.

## Why this exists

Copilot flagged on PR #7 that the `knowledge_chunks` schema in `CLAUDE.md`
named a `chunk_index` column and omitted `chunk_hash`. The actual migration
ships `chunk_order`, `chunk_hash`, plus `project_key` and `metadata`. The
`knowledge_documents` summary was similarly partial. Both errors would have
been caught by a 30-second look at
`database/migrations/2026_01_01_000002_create_knowledge_chunks_table.php`.

A wrong schema in `CLAUDE.md` is load-bearing damage: future PRs use the
file as a quick-reference and silently propagate the mistake into queries,
tests, and follow-up docs.

## Patterns

### Cross-check a schema before quoting it

```bash
# Bash one-liner: list every column the migration actually creates
php artisan tinker --execute="echo collect(Schema::getColumnListing('knowledge_chunks'))->implode(', ');"

# …or just grep the migration:
grep -E "\\\$table->" database/migrations/*knowledge_chunks*
```

Then write the doc against that output, not from memory.

### Cross-check an env var

```bash
grep '^KB_' .env.example                    # env names + comments
grep -RIn 'config(.kb' app/ config/         # who reads them
grep -RIn 'env(.KB_' config/                # default chain
```

The README quick-start, `.env.example`, and `config/kb.php` must agree on
the **same** key name with the **same** default. If they don't, fix all
three in the same diff (this is also rule R6).

### Cross-check an Artisan signature

```bash
php artisan kb:ingest-folder --help
```

Copy the signature/options from the help output, not from a half-remembered
PR description.

## Checklist before opening a PR that touches docs

- [ ] Every column name quoted in `CLAUDE.md` /
      `.github/copilot-instructions.md` matches a `$table->` line in the
      corresponding migration.
- [ ] Every env var quoted matches `.env.example` AND the `env('…')` call
      in `config/*.php`.
- [ ] Every command name + flag matches `php artisan <command> --help`.
- [ ] Every route path matches `routes/api.php` / `routes/web.php`.
- [ ] Every class / method referenced in a code snippet actually exists
      (open it; do not paste from memory).
- [ ] When you rename a column, env var, config key, command flag, or
      route, grep the docs and update every mention in the same commit.

## Counter-example

```markdown
### `knowledge_chunks`
`id`, `knowledge_document_id` FK, `chunk_index`, `chunk_text`,
`heading_path`, `embedding vector(N)`.
```

The actual migration creates `chunk_order` (not `chunk_index`) and a
`chunk_hash` column the doc forgot. Anyone writing a query off this doc
will get a "column does not exist" error.

## Correct example

```markdown
### `knowledge_chunks`
`id`, `knowledge_document_id` FK (ON DELETE CASCADE), `project_key`,
`chunk_order`, `chunk_hash` (SHA-256), `heading_path`, `chunk_text`,
`metadata` JSON, `embedding vector(N)`. UNIQUE
`(knowledge_document_id, chunk_hash)`.
```

Each column name copied straight from
`database/migrations/2026_01_01_000002_create_knowledge_chunks_table.php`.
