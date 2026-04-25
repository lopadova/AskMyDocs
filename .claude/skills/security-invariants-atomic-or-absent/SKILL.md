---
name: security-invariants-atomic-or-absent
description: Any single-use / rate-limit / auth / nonce check must hold the lock until the invariant is RECORDED — or the invariant does not exist. `lockForUpdate()` read + `update()` write must live in the SAME `DB::transaction` closure. Concurrency-sensitive state gets DB-level UNIQUE backing where the business rule demands it. One occurrence mints a skill + rule because the blast radius is RCE-class (single-use bypass, TOCTOU, signed-URL replay). Trigger when editing any service that consumes nonces, confirm tokens, rate counters, auth bypasses, or single-use resources.
---

# Security invariants are atomic or absent

## Rule

Any invariant that crosses a concurrency boundary must hold the lock
until the invariant is RECORDED. A `lockForUpdate()` read, a policy
check against the returned row, and the `update()` that marks it
consumed — ALL THREE live inside the SAME `DB::transaction` closure
(or the equivalent single `SELECT ... FOR UPDATE; UPDATE ...`
round-trip).

Read-outside-write is a textbook race window:

```
Thread A: BEGIN → SELECT FOR UPDATE (row, used_at=null) → COMMIT
Thread B:          BEGIN → SELECT FOR UPDATE (row, used_at=null) → COMMIT
Thread A: UPDATE used_at=now()  (too late — B already saw null)
Thread B: UPDATE used_at=now()
Both threads proceed believing they hold the single-use invariant.
```

For destructive-command confirm tokens, signed URLs, one-time
passwords, email-verification links, and anti-replay counters, the
above is **not "rare" — it is the textbook race window**, and the
consequence is RCE-class (single-use bypass on an admin command
runner → untrusted input executes privileged Artisan commands).

## Symptoms in a review diff

- `DB::transaction(fn () => $row->lockForUpdate()->first())` followed
  by `$row->update(...)` OUTSIDE the closure.
- `Cache::lock($key)->get(fn () => ...)` that releases before the
  state mutation commits.
- Single-use column (`used_at`, `consumed_at`, `redeemed_at`,
  `revoked_at`) with no `UNIQUE` or no `WHERE used_at IS NULL`
  guard on the UPDATE.
- `firstOrCreate` on a single-use nonce without a DB-level unique
  index backing the intent.
- Signature / nonce validation that sleeps / logs / calls an
  external service between "validate" and "mark consumed".

## How to detect in-code

```bash
# lockForUpdate read outside of a transaction closure
rg -n -A 8 'lockForUpdate\(\)' app/Services/

# firstOrCreate on a nonce-ish table
rg -n 'firstOrCreate\(' app/ | rg -i 'nonce|token|confirm|used_at'

# update() on used_at without a where used_at null
rg -n "->update\(\['used_at" app/ -B2
```

## Fix templates

### Move the update INSIDE the transaction (PR #29 `consumeConfirmToken`, `59d95bc`)

```php
// ❌ — the fix commit's before-shape
public function consumeConfirmToken(string $raw): ?AdminCommandNonce
{
    $hash = hash('sha256', $raw);

    $row = DB::transaction(function () use ($hash) {
        return AdminCommandNonce::query()
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->lockForUpdate()
            ->first();
    });

    if (! $row) return null;

    // ⚠ RACE WINDOW: the lock was released when the transaction closed.
    // Two concurrent callers can each reach here with the same row.
    $row->update(['used_at' => now()]);
    return $row;
}

// ✅
public function consumeConfirmToken(string $raw): ?AdminCommandNonce
{
    $hash = hash('sha256', $raw);

    return DB::transaction(function () use ($hash) {
        $row = AdminCommandNonce::query()
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->lockForUpdate()
            ->first();
        if (! $row) return null;

        // Update INSIDE the closure, under the row lock.
        $row->update(['used_at' => now()]);
        return $row;
    });
}
```

### DB-level uniqueness for single-use resources

The in-code check is belt-and-suspenders — the DB constraint is the
suspenders. Ensure the migration has a UNIQUE index that encodes the
invariant:

```php
// database/migrations/..._create_admin_command_nonces.php
Schema::create('admin_command_nonces', function (Blueprint $t) {
    $t->id();
    $t->string('token_hash', 64);
    $t->timestamp('used_at')->nullable();
    $t->timestamps();

    // Invariant: a given token hash can only ever be consumed once.
    // The UNIQUE constraint on (token_hash) is a SECOND barrier in
    // case the code-path lock fails. If two concurrent writers race
    // past the lock, the second INSERT / UPDATE fails.
    $t->unique('token_hash');
});
```

For partial-unique semantics (PostgreSQL only):

```php
// Partial unique index: only one un-consumed row per hash.
DB::statement(
    "CREATE UNIQUE INDEX uq_nonce_active ON admin_command_nonces (token_hash) WHERE used_at IS NULL"
);
```

### Guard the UPDATE explicitly

```php
// ✅ — even with the row lock, mark the UPDATE idempotent
$affected = AdminCommandNonce::query()
    ->where('id', $row->id)
    ->whereNull('used_at')
    ->update(['used_at' => now()]);

if ($affected === 0) {
    // Someone else consumed it — treat as failure, not success.
    return null;
}
```

### Concurrent-access regression test

```php
public function test_consume_is_atomic_under_concurrent_callers(): void
{
    $service = app(CommandRunnerService::class);
    $token = $service->issueConfirmToken(...);

    // Fire two "processes" from within a single PHPUnit run by
    // forking via pcntl (or use pthreads / parallel / a queue-driven
    // spec). Assert exactly one returns a nonce; the other returns
    // null.
    $results = ParallelHelper::run(2, fn () => $service->consumeConfirmToken($token));

    $wins = array_filter($results);
    $this->assertCount(1, $wins, 'exactly one concurrent consume wins');
}
```

If parallel testing isn't feasible in your suite, simulate the race
by advancing to the `lockForUpdate()` read in one fiber, then
starting a second fiber that races to the same row, and assert the
second one sees `used_at != null`.

## Related rules

- R4 — no silent failures: a concurrent-consume race that silently
  "works" because both callers see `used_at = null` is R4 + R21
  together.
- R14 — surface failures loudly: when `$affected === 0`, return
  `null` / 409 Conflict, not a 200 with a forged success body.
- R19 — input escape is a different security rule — injection paths.
  R21 covers concurrency paths.

## Enforcement

- No dedicated CI script — detecting "is the update inside the
  lock?" statically is non-trivial. The
  `copilot-review-anticipator` sub-agent greps for `lockForUpdate()`
  + same-file `update(['used_at`/`consumed_at`/`revoked_at`) and
  flags any pair where they're not inside the same transaction
  closure.
- Test discipline: any new service with a `consume*` / `redeem*` /
  `revoke*` method MUST ship a concurrency regression test (fork /
  parallel / race helper). If none feasible, a commit-message
  comment explaining why.
- Code review: `security` tag in COPILOT-FINDINGS.md is the
  highest-priority tag. Any row tagged `security` is a release
  blocker even on a "tiny PR".

## Counter-example

```php
// ❌ The PR #29 before-fix shape, literally
$row = DB::transaction(fn () => AdminCommandNonce::where('token_hash', $h)
    ->whereNull('used_at')->lockForUpdate()->first());

if (! $row) return null;
$row->update(['used_at' => now()]);     // lock RELEASED above; race window open
return $row;
```

## Further reading

- PostgreSQL docs, "Explicit Locking" §13.3.2 — `SELECT ... FOR
  UPDATE` scope is tied to the transaction, not the statement.
- Laravel: `Illuminate\Database\Concerns\BuildsQueries::lockForUpdate`
  source — the lock is released on commit/rollback.
- OWASP "Race Conditions" category — covers TOCTOU and single-use
  bypass classes.
