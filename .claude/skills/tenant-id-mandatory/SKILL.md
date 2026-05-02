---
name: tenant-id-mandatory
description: Every tenant-aware Eloquent model in app/Models/ MUST `use BelongsToTenant;` and have `tenant_id` in $fillable (or use `$guarded = ['id']`). The TenantContext singleton is set by ResolveTenant middleware (HTTP) or `--tenant=X` CLI option. v3 backward compat is preserved by `default 'default'` everywhere. Trigger when adding a new Eloquent model under app/Models/, when modifying $fillable on an existing model, when creating a new migration that adds a domain table, or when reviewing PRs that touch persistence.
---

# R31 — tenant_id is mandatory in every tenant-aware model

## Rule

Every Eloquent model in `app/Models/` that represents a **tenant-scoped
domain entity** MUST:

1. **Use the `BelongsToTenant` trait** (auto-fills tenant_id on creating)
2. Either:
   - List `'tenant_id'` in `$fillable`, OR
   - Use `$guarded = ['id']` / `[]` (full mass-assignment except PK)

Excluded on purpose:
- `User` (cross-tenant identity)
- System tables (jobs, failed_jobs, activity_log) — handled by Laravel/Spatie

## Why

Multi-tenant boundary enforcement starts at the model layer. Without
this trait + fillable, `Model::create(['project_key' => 'x'])` silently
creates a row with `tenant_id` defaulting to `'default'` (correct) — but
when a controller passes a real tenant_id explicitly, it must reach the
DB. `$fillable` is the gate; `BelongsToTenant` is the safety net.

## How to apply (new model)

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MyNewDomainEntity extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',  // <-- always FIRST
        'project_key',
        // ... other columns
    ];
}
```

## How to apply (new migration)

```php
Schema::create('my_new_table', function (Blueprint $t) {
    $t->id();
    $t->string('tenant_id', 50)->default('default')->index();
    // ... other columns

    // Tenant-scoped uniques: always (tenant_id, ...other_cols)
    $t->unique(['tenant_id', 'slug']);
});
```

Do NOT forget the index on `tenant_id` (R30 read-side performance).
Do NOT forget the composite unique starting with tenant_id where business
rules require uniqueness within a tenant.

## Architecture test (auto-enforced)

`tests/Architecture/TenantIdMandatoryTest.php` enumerates `TENANT_AWARE_MODELS`
and asserts the trait + fillable rules. **Adding a new tenant-aware
model? You must also add it to that list.**

## Counter-examples (DO NOT)

```php
// ❌ Missing trait
class MyModel extends Model
{
    protected $fillable = ['tenant_id', 'project_key'];
}

// ❌ Missing tenant_id in fillable (and not using $guarded)
class MyModel extends Model
{
    use BelongsToTenant;
    protected $fillable = ['project_key', 'name'];  // tenant_id absent
}

// ❌ Migration without tenant_id default
Schema::create('orders', function (Blueprint $t) {
    $t->string('tenant_id', 50);  // no default → factory rows fail
});
```

## Reference

- `app/Models/Concerns/BelongsToTenant.php` — the trait
- `app/Support/TenantContext.php` — request-scoped singleton
- `app/Http/Middleware/ResolveTenant.php` — HTTP entry point
- `tests/Architecture/TenantIdMandatoryTest.php` — gate
- `CLAUDE.md` R31 (codified rule)
