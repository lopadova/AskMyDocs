# ADR 0033 — Auto-Wiki maintenance as a delegated routine

- **Status:** Accepted
- **Date:** 2026-09-26
- **Cycle:** v8.39 (W5 of the Document Intelligence & LLM Wiki Export cycle,
  the last of the five workstreams the plan schedules — W6 is an optional
  adjacency, not a sixth scheduled release).
- **Builds on:** [ADR 0003](0003-promotion-pipeline.md) (agent proposes, a
  person commits), [ADR 0014](0014-v811-auto-wiki-tier.md) (`human > auto`
  — a maintenance run only ever touches `auto`-tier pages), R30/R31 tenant
  scoping, R43 both-state flags, R44 tri-surface, R32 admin-route gating.
- **Plan:** [PLAN v8.36 → v8.40](../v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md)
  §W5.

## Context

`kb:wiki-maintain` (v8.11/P9) already runs nightly, scheduled as `kb_wiki_maintain`
in `TierOneSchedulerRegistrar` (`SCHEDULE_KB_WIKI_MAINTAIN_CRON`, default
`40 4 * * *`), delegating to `WikiMaintainer::maintain()` for index rebuild
+ lint + backfill of un-enriched docs, scoped to ONE tenant per invocation
(`--tenant=default` today — the same pre-existing single-tenant scoping the
Tier-1 scheduler already has for this slot; W5 does not change that scope,
only who triggers it). It runs as `system:autowiki` — nobody's mandate,
nobody's spend, nobody to ask when it wants to do something a person should
decide. That is fine for "rebuild the index" (idempotent, reversible,
cheap) and would be wrong for anything that could plausibly deprecate a
page a human already vouched for — which is exactly why `WikiMaintainer`
never does that today, and this ADR does not change what it does.

A competitor pattern (`lucasastorian/llmwiki`) tells its users to *"set up
a Claude Routine so Claude refreshes the wiki"* — maintenance as something
the user delegates, not something the infrastructure just does. This
workstream gives AskMyDocs the same framing using a real, installed engine:
**`padosoft/laravel-routines` v1.2.0**, whose `RoutineTarget` contract
(`padosoft/laravel-routines-contracts` v1.2.0) is exactly the "core doesn't
know what it's launching" seam this integration needs.

### Correction to the plan's own framing (found during implementation)

The plan describes `laravel-routines`/`laravel-routines-contracts` as
"an explicit new dependency... default the target OFF" in the same spirit
as the optional IAM dependency ADR 0028 flagged, implying the ENGINE
package might legitimately be *absent* at runtime and every touch point
needs an `interface_exists()`/`class_exists()` guard against that. Once
both packages were actually available and inspected, this repo's own
existing convention for every other `padosoft/*` integration (`laravel-flow`,
`laravel-invitations`, `laravel-ai-act-compliance`, …) turned out to be
different: **every padosoft package is a normal `require` dependency,
always installed in every environment and CI** — "optional" in this
codebase means *the feature you may not configure or enable*, not *the
package you may not have installed*. `interface_exists()` guards on those
existing integrations exist for forward-safety against an incomplete
package version, not against genuine absence.

This ADR follows that established convention rather than the plan's literal
wording: `padosoft/laravel-routines-contracts` **and**
`padosoft/laravel-routines` are both added to `composer.json`'s `require`
block (not `require-dev`, not detected-at-runtime-optional). The
`interface_exists(RoutineTarget::class)` / `class_exists(TargetRegistry::class)`
guards in §3 stay, for the same forward-safety reason every other
integration in this codebase keeps them — not because either package is
expected to be missing.

## Decision

### 1. `KB_WIKI_ROUTINE_ENABLED` — default OFF, both states tested (R43)

Gates target *registration* (§3) and the mutating tri-surface entry points
(CLI `run`, `POST .../wiki-routine/run`). The read surfaces (`kb:wiki-routine
status`, `GET .../wiki-routine`, `KbWikiRoutineStatusTool`) are always
registered and answer `{disabled: true, flag: 'KB_WIKI_ROUTINE_ENABLED'}`
when the flag is off — the same posture `KbOcrStatusTool` / `KbReviewStatusTool`
/ `KbGetExportTool` already take, so a config-dependent MCP roster never
desyncs from the file list `KnowledgeBaseServerRegistrationTest` derives.
With the flag off, `kb:wiki-maintain`'s scheduler entry is byte-identical to
before this ADR (§6).

### 2. Dependency — required, not runtime-optional (see the correction above)

`composer.json` `require`: `padosoft/laravel-routines-contracts: ^1.2`,
`padosoft/laravel-routines: ^1.2`. The engine package auto-registers
(`RoutinesServiceProvider`, Laravel package discovery) its own
`TargetRegistry`/`RoutineScheduler`/`RoutineDispatcher`/`RoutineManager`
singletons, its own `routines:tick` scheduler entry (every minute,
`withoutOverlapping`, `runInBackground` — this is what actually *fires* due
routines; AskMyDocs never builds its own dispatcher), a default `JobTarget`
(unrelated to this ADR, `routines.targets.job.allowed` ships empty), and a
generic admin API at `api/routines/v1`. §5 below explains why that generic
API is turned OFF for this deployment.

### 3. `WikiMaintenanceRoutineTarget` — a thin adapter, not a new core

```php
namespace App\Routines;

use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Target\{RoutineTarget, TargetDescriptor, TargetResult, TargetOutcome, ValidationResult};

final class WikiMaintenanceRoutineTarget implements RoutineTarget
{
    // STABLE FOREVER (RoutineTarget::type()'s own contract) — changing it
    // orphans every existing Routine row's history.
    public const TYPE = 'askmydocs.wiki_maintenance';

    public function __construct(private readonly WikiMaintainer $maintainer) {}

    public function type(): string { return self::TYPE; }

    public function descriptor(): TargetDescriptor
    {
        return new TargetDescriptor(
            label: 'AskMyDocs — Wiki Maintenance',
            summary: 'Rebuilds the wiki index, lints (optionally fixes), and backfills un-enriched documents for a tenant/project.',
            fields: [
                'tenant' => ['label' => 'Tenant', 'type' => 'string', 'required' => true],
                'project' => ['label' => 'Project (optional)', 'type' => 'string'],
                'fix' => ['label' => 'Apply safe lint auto-fixes', 'type' => 'boolean'],
                'backfill_limit' => ['label' => 'Backfill limit', 'type' => 'integer'],
            ],
            actionClasses: ['kb.wiki.compile', 'kb.wiki.lint'],
            supportsPause: false,   // WikiMaintainer::maintain() is a single synchronous call, no resume state
            reportsCost: false,    // no FinOps metering on this path today
        );
    }

    public function validate(array $payload): ValidationResult
    {
        // mirrors kb:wiki-maintain's own option shape — same fields, same defaults.
        $errors = [];
        if (! is_string($payload['tenant'] ?? null) || $payload['tenant'] === '') {
            $errors['tenant'] = ['Required.'];
        }
        return $errors === [] ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    public function fire(RoutineExecution $execution): TargetResult
    {
        // No try/catch: WikiMaintainer::maintain() either returns a
        // summary or throws, and it has no "reported failure" shape of
        // its own for TargetResult::failed() to wrap. RoutineTarget::
        // fire()'s own contract says an unhandled throw here is correct
        // — "the core treats it as such" (an unexpected failure, not a
        // foreseen one).
        $result = $this->maintainer->maintain(
            (string) $execution->payload('tenant'),
            $execution->payload('project'),
            (bool) $execution->payload('fix', false),
            $execution->payload('backfill_limit'),
        );

        return TargetResult::succeeded(
            message: sprintf(
                'Maintained %d project(s): %d lint issue(s), %d doc(s) backfilled.',
                count($result['projects']), $result['lint_issues'], $result['backfilled'],
            ),
            metadata: $result,
        );
    }
}
```

No business logic lives in the adapter — `WikiMaintainer::maintain()` is
the one place maintenance logic exists, exactly as it is today; the
adapter only translates between `RoutineTarget`'s contract and that
existing method's signature. `fire()` never throws `MandateExceeded`
(`padosoft/laravel-routines-contracts`'s `Consent\MandateExceeded`) because
`WikiMaintainer` has exactly two capabilities, both declared in
`descriptor()->actionClasses` and both always in scope for what it does —
see §7 for why this makes the mandate/pause-and-ask machinery real but
currently unreachable, and why that is a documented, deliberate gap rather
than an oversight.

Registration: `AppServiceProvider::registerWikiRoutineTarget()`, called
from `boot()`, guarded on `config('kb.wiki_routine.enabled')` **and** —
forward-safety, §2 — `interface_exists(RoutineTarget::class) &&
class_exists(TargetRegistry::class)`:

```php
private function registerWikiRoutineTarget(): void
{
    if (! (bool) config('kb.wiki_routine.enabled', false)) {
        return;
    }
    if (! interface_exists(\Padosoft\Routines\Contracts\Target\RoutineTarget::class)
        || ! class_exists(\Padosoft\Routines\Targets\TargetRegistry::class)) {
        Log::warning('Wiki routine target enabled but laravel-routines contracts/engine unavailable.');
        return;
    }
    $this->app->make(\Padosoft\Routines\Targets\TargetRegistry::class)
        ->register($this->app->make(\App\Routines\WikiMaintenanceRoutineTarget::class));
}
```

### 4. Auto-provisioning the `Routine` row, not a creation UI

`padosoft/laravel-routines` ships its own generic routine-CRUD admin API
(§5 — disabled here); building a second, wiki-specific creation form is out
of scope. Instead, `WikiRoutineService::ensureRoutine(string $tenantId):
Routine` — called by both `run` entry points (CLI + HTTP, never the
read-only MCP tool) — looks up an existing `Routine` row scoped by
`target_type = WikiMaintenanceRoutineTarget::TYPE` AND `organization_id =
$tenantId` (the package's generic `organization_id` column is where this
app's tenant id lives — the engine has no concept of a tenant, R30 scoping
is entirely this service's job, exactly like scoping any other third-party
table the host doesn't own), and creates one via `RoutineManager::create()`
on first use:

```php
'owner' => 'system:askmydocs-wiki-maintenance',
'organization_id' => $tenantId,
'name' => "Wiki maintenance — {$tenantId}",
'target_type' => WikiMaintenanceRoutineTarget::TYPE,
'target_payload' => ['tenant' => $tenantId],
'trigger_kind' => 'cron',
'cron' => config('askmydocs.schedule.kb_wiki_maintain.cron', '40 4 * * *'),  // same time as the cron it's replacing
'initiation' => 'system',
```

No mandate is granted at creation — see §7.

The find-then-create above is wrapped in `Cache::lock('wiki-routine-provision:'.$tenantId,
15)->block(10, ...)` (an unguarded find-then-create would be a TOCTOU race:
two concurrent `run()` calls for the same tenant — a double-click on "Run
now," or a concurrent HTTP+CLI trigger — could both see "not provisioned"
and both insert a `Routine` row, since `routines` carries no unique
constraint on `(target_type, organization_id)`; caught by an independent
review of this PR, R21). `WikiRoutineService::provisionLockKey($tenantId)`
exposes the exact key so a test can hold it externally and prove real
mutual exclusion, mirroring `App\Support\Kb\SourceKeyLock`'s convention.

### 5. The package's own generic admin API is turned OFF (`ROUTINES_API_ENABLED=false`)

`padosoft/laravel-routines`'s default `config('routines.api.middleware') =
['web', 'auth']` uses the **session** `auth` guard (not `auth:sanctum`, not
tenant-scoped, not RBAC-role-scoped) and its own authorization is a
generic Gate (`routines.read`/`.write`/`.fire`/`.approve`) that, per the
package's own config comment, degrades to **read-only for any
authenticated user with no policy defined** — and the package has no
concept of AskMyDocs's tenant boundary at all, so an authenticated member
of ANY tenant could read every tenant's routines through that generic API.
Building a tenant-aware policy layer for a generic cross-domain surface
this ADR does not otherwise need is out of scope; the conservative,
correctly-scoped choice is to publish `config/routines.php` with
`api.enabled = false` (`ROUTINES_API_ENABLED=false` in `.env.example`) and
let AskMyDocs's own tenant-scoped, RBAC-gated tri-surface (§6) be the ONLY
sanctioned way to read or trigger a routine in this application. The
signed webhook ingress (`hooks/routines/{id}`, HMAC-per-routine) is turned
OFF too (`ROUTINES_WEBHOOKS_ENABLED=false`, `config/routines.php`
`webhooks.enabled`) — an independent review of this PR caught that the
package mounts it by default, and nothing this cycle creates a
`trigger_kind=webhook` routine, so leaving it on would be unreasoned
public attack surface for zero functional benefit. The route is soundly
built when it IS enabled (HMAC-SHA256 over the raw body, constant-time
compare, per-routine secret, replay window, anti-enumeration 404), so
turning it on later for an actual webhook-triggered routine is safe — this
is "off until something needs it," not a statement about the route's own
security.

### 6. No double run, no orphaned run

`kb_wiki_maintain` is removed from `TierOneSchedulerRegistrar::SLOTS`'s
plain list and registered directly in `bootstrap/app.php`'s
`withSchedule()` closure, mirroring the existing `compositeGatedSlots()`
pattern for `eval_nightly`/`ai_act_regulatory_poll` — except the extra gate
here is a DB check, not a config boolean, so it needs its own guard against
the one new failure mode a config read never has: the database being
unreachable at schedule-registration time (which runs on every console
boot, not only `schedule:run`).

`App\Routines\WikiMaintenanceRoutineGate::cronSlotShouldStayActive(): bool`
returns **true** (keep the legacy cron — the safe default) unless ALL of:

1. `KB_WIKI_ROUTINE_ENABLED=true`
2. The adapter is registered (`interface_exists`/`class_exists`, §3)
3. An **active** `Routine` row exists for `target_type =
   WikiMaintenanceRoutineTarget::TYPE` **AND** `organization_id =
   'default'` — checked in a `try/catch`; any `\Throwable` (DB down, table
   not yet migrated) is caught, logged, and treated as "not ready" — the
   cron stays on. A missing table or an unreachable database is one more
   way this condition is "not satisfied yet," never a reason to lose the
   nightly run entirely.

   The `organization_id = 'default'` scope is load-bearing, not
   decoration: the legacy `kb_wiki_maintain` slot only ever maintains
   tenant `default` (`KbWikiMaintainCommand`'s own `--tenant=default`
   default). An earlier draft of this gate checked ANY active routine,
   regardless of tenant — caught by an independent review of this PR
   before merge: any OTHER tenant enabling the routine for itself would
   have silently disabled the shared cron slot `default` still depends
   on, with no error and no log naming the loss.

```php
// bootstrap/app.php, inside withSchedule(), replacing the plain SLOTS entry
if (! app(\App\Routines\WikiMaintenanceRoutineGate::class)->cronSlotShouldStayActive()) {
    // The routine now owns this — routines:tick (registered by the engine
    // package itself) fires it, not this scheduler.
} else {
    $registrar->registerSlot($schedule, 'kb_wiki_maintain', 'kb:wiki-maintain');
}
```

Any of the three missing → the scheduler entry is byte-identical to before
this ADR, and a log line names which condition failed. Tests cover "flag
on, no Routine row yet" (cron stays on), "flag on, active Routine row
exists for `default`" (cron turns off), and — the regression this PR's
review caught — "flag on, active Routine row exists for a DIFFERENT
tenant" (cron stays on for `default` regardless).

### 7. What the mandate/pause-and-ask machinery does NOT do this cycle — an honest gap, not an oversight

The plan describes the routine as having "a mandate (`kb.wiki.compile`,
`kb.wiki.lint`), a spend ceiling, and a pause-and-ask when it wants to do
something outside the mandate." Implementation surfaced that
`RoutineMandate` is granted through an explicit, separate step-up-consent
flow (`RoutineManager::grantMandate()`, requiring a `confirmationId`/`aal`
evidence pair — the package's own step-up authentication UI, which this
app does not yet integrate) and that `RoutineManager::mandateCovers()`
returns `true` — i.e., **unrestricted** — for any routine that was never
granted one (*"nessun mandato = nessun vincolo da violare"*, the package's
own fail-open-when-absent design for the routine's OWN authority level,
distinct from `RoutineMandate::covers()`'s fail-closed behaviour once a
mandate DOES exist).

This ADR does **not** wire that consent flow. `WikiRoutineService::ensureRoutine()`
(§4) creates the `Routine` row with no mandate at all — the routine runs
**as the application**, with exactly the same authority `kb:wiki-maintain`'s
cron already has today. This is not a regression (the blast radius is
identical to before this ADR — `WikiMaintainer` has never been able to do
anything outside `kb.wiki.compile`/`kb.wiki.lint`), but it does mean:

- The `MandateExceeded`/pause-and-ask path described in §3 is real,
  wired-through machinery that is currently **unreachable**: nothing this
  cycle can trigger it, because (a) the adapter's own capability never
  exceeds its declared action classes, and (b) even if it did, an
  unmandated routine has no mandate to exceed.
- A future cycle that wants the actual delegated-consent UX the plan
  describes needs to build the step-up confirmation flow and call
  `RoutineManager::grantMandate()` from it — genuinely separate scope from
  this ADR's "give the existing cron a routine identity" goal, and large
  enough to be its own workstream rather than a W5 sub-point.

Recorded here rather than silently shipped as if the plan's framing were
fully realised — the "S/M" sizing this workstream carries in the plan is
accurate for what §1-§6 ship; it would not have been accurate for the
consent UI as well.

### 8. Tri-surface (R44)

| Capability | PHP / CLI | HTTP | MCP |
|---|---|---|---|
| Read status (routine state, last run, no mandate/consent yet) | `kb:wiki-routine status --tenant=` | `GET /api/admin/kb/wiki-routine` | `KbWikiRoutineStatusTool` (read) |
| Run now | `kb:wiki-routine run --tenant=` | `POST /api/admin/kb/wiki-routine/run` (`role:admin\|super-admin`) | — (documented R44 exception, below) |

**Documented R44 exception: no MCP `run` tool.** Starting the routine
spends (a `RoutineRun` row, and — once §7's gap is closed in a future
cycle — real budget) and rewrites `auto`-tier pages. By this cycle's own
invariant (the same posture W1's OCR re-run and W3's review-approval MCP
surfaces already took), an agent may **see** the routine's state, never
**start** it. `KnowledgeBaseServerRegistrationTest` derives the tool
roster from `app/Mcp/Tools/*`, so the absence of a run tool is provable,
not merely asserted in prose.

`kb:wiki-routine {status|run}` is one command with two subcommand-like
arguments (mirroring `kb:review`'s `--report` vs. mutating split), each
delegating to the same `WikiRoutineService` the HTTP controller and MCP
tool use — one core, three thin surfaces (R44).

### 9. Doc-site (R45)

`docs-site/auto-wiki.mdx` gains a "Maintenance as a routine" section:
what the routine's tri-surface reports, why there is no MCP run tool, and
an explicit statement of §7's gap (no mandate/consent flow yet — the
routine runs as the application) so a reader does not go looking for a
step-up approval screen that does not exist.

## Consequences

- Maintenance goes from an unnamed, unobservable cron entry to a `Routine`
  row a tenant can query the status of and trigger on demand — closing the
  same competitor-parity gap W4 closed for export, this time for the
  recurring upkeep loop.
- `WikiMaintainer` gains no new capability and no new risk: the adapter is
  a thin translation layer over the exact method the unattended cron
  already calls today.
- §6's three-condition-plus-DB-check gate means flipping
  `KB_WIKI_ROUTINE_ENABLED` before a `Routine` row exists for a tenant, or
  with the database briefly unreachable, degrades to "nothing changes, and
  the log says why" — never "maintenance silently stops running."
- §5 keeps AskMyDocs's tenant boundary intact against a generic,
  cross-tenant admin surface the engine package ships by default but this
  application does not want exposed.
- §7 is the honest cost of shipping this at S/M size: the routine has an
  identity and is observable and triggerable, but the delegated-consent
  "ask a human when it exceeds its mandate" story from the plan's own
  framing is real, wired machinery with nothing yet able to exercise it —
  documented rather than glossed over, matching every other deferred item
  this plan has named explicitly instead of silently dropping.

## Surfaces (R44)

- **PHP / CLI:** `kb:wiki-routine {status|run} --tenant=`;
  `WikiMaintenanceRoutineTarget` (adapter, registered behind
  `config('kb.wiki_routine.enabled')` + `interface_exists`/`class_exists`);
  `WikiRoutineService` (the one core); `WikiMaintenanceRoutineGate` (the
  scheduler-vs-routine decision, §6).
- **HTTP:** `GET /api/admin/kb/wiki-routine` (status), `POST
  /api/admin/kb/wiki-routine/run` (`role:admin|super-admin`).
- **MCP:** `KbWikiRoutineStatusTool` (read-only). No `run` tool —
  documented exception, §8.
