---
name: rbac-authorization-matrix
description: R32 — every new protected route / API / admin screen / Gate / role MUST be added to the RBAC authorization-matrix test before merge. Use whenever you add or change a route group, a Gate::define, a role, a permission, or an admin SPA screen.
---

# R32 — RBAC access-control is a regression gate, not a per-PR afterthought

## The rule

Whenever you add or change ANY of:

- a **route** or **route group** under an authenticated prefix (`/api/admin/*`, `/api/kb/*`, package-registered admin routes),
- a **`Gate::define()`** ability or change its role-set,
- a **role** (Spatie `RbacSeeder::ROLES`) or a **permission** (`RbacSeeder::PERMISSIONS`),
- an **admin SPA screen / nav entry** (`frontend/src/routes/index.tsx`),
- a **feature flag** that conditionally registers protected routes,

you MUST, in the SAME PR, add or update the corresponding row in:

- **API:** `tests/Feature/Security/AdminAuthorizationMatrixTest.php` — the `matrix()` array maps a representative no-path-param endpoint of the group → the EXACT allow-set of roles.
- **UI:** `frontend/e2e/role-access.spec.ts` — per-role nav/screen visibility + reachability.

A PR that adds a protected surface without touching these is **incomplete** and must be blocked in review.

## Why (this is graded on blast radius, not frequency)

Per-controller tests each cover one endpoint, usually for one or two roles. A new route that forgets its `role:` / `can:` middleware, or a package that mounts routes with a permissive default, ships **green** because nothing systematically checks "can the wrong role reach this?".

This is not hypothetical. The matrix's very first run (v8.4) caught
`api/admin/ai-act-compliance/*` mounted with the package default
`routes.middleware => ['api']` — **no auth, no gate** — exposing DSAR
(personal-data requests), incidents, bias data, the risk register and consent
records to **unauthenticated** callers. One missing config key = a public data
breach. The matrix turned a silent P0 into a failing test.

## How to apply

### 1. API — extend the matrix

```php
// tests/Feature/Security/AdminAuthorizationMatrixTest.php
private function matrix(): array
{
    return [
        // ... existing rows ...
        '/api/admin/my-new-feature' => ['admin', 'super-admin'], // ← add this
    ];
}
```

The test does the rest, for every role + the guest:

- role NOT in the allow-set → must get **exactly 403** (gate denied).
- role IN the allow-set → must get **anything-but-403** (gate passed; the
  controller may 200/404/422/500 on data — not an authz concern).
- guest → must get **401**.

Pick a representative **no-path-param GET** for the group (`/index` style). One
endpoint exercises the group's gate; you don't need every verb.

Derive the allow-set from the source of truth:
- group middleware `role:a|b` in `routes/api.php`, or
- the `Gate::define('ability', fn ($u) => $u->hasAnyRole([...]))` in
  `app/Providers/AppServiceProvider.php`.

### 2. Package-registered routes — gate them in config, then matrix them

If a vendor package registers admin routes, its default middleware is often
just `['api']`. Override it in the host `config/<package>.php`
`routes.middleware` with the authenticated admin stack:

```php
'middleware' => [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:<theGate>',
],
```

and load that host config in `tests/TestCase.php::getEnvironmentSetUp` (Testbench
does NOT auto-load host `config/` files) so the matrix verifies the SECURE config,
not the package default.

### 3. UI — extend the per-role Playwright spec

`frontend/e2e/role-access.spec.ts` logs in as each role (storage states under
`playwright/.auth/<role>.json`, seeded via the per-role `*.setup.ts`) and asserts
which admin nav items + screens are reachable vs blocked. Add a row for the new
screen and the roles that may see it.

## Counter-examples (DO NOT)

```php
// ❌ Adding a route group with a gate but no matrix row — the gate is
//    untested; a later refactor that drops the gate ships green.
Route::middleware(['auth:sanctum', 'can:viewBilling'])->prefix('admin/billing')->group(...);
// (no row added to AdminAuthorizationMatrixTest::matrix())

// ❌ Trusting a package's route middleware default without checking it.
//    `route:list -v` the package prefix; if you see only `api`, it is OPEN.

// ❌ Asserting only the happy path ("admin can see it") and never the
//    negative ("viewer/guest CANNOT"). The negative is the security property.
```

## Checklist before merge

- [ ] New protected route/group → row in `AdminAuthorizationMatrixTest::matrix()`.
- [ ] New `Gate::define` / changed role-set → matrix allow-sets updated.
- [ ] New role/permission → `RbacSeeder` + `ALL_ROLES` const + matrix re-derived.
- [ ] Package-registered routes → host `config/<pkg>.php` `routes.middleware` gated + loaded in `TestCase`.
- [ ] New admin screen → `role-access.spec.ts` row.
- [ ] `route:list -v <prefix>` shows `auth:sanctum` + the gate on every new protected route.
- [ ] Matrix test green; negative cases (wrong role → 403, guest → 401) assert, not just the happy path.
