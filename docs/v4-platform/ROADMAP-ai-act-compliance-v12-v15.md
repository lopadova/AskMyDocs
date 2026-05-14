# Roadmap — laravel-ai-act-compliance v1.2 → v1.5

**Status:** drafted 2026-05-15. Covers the next four minor releases of the
`padosoft/laravel-ai-act-compliance` package and its admin SPA companion
`padosoft/laravel-ai-act-compliance-admin`, plus the AskMyDocs host
integration that follows the v1.5 final tag.

**Authoritative source files referenced while drafting**:
- `C:\Users\lopad\Documents\DocLore\Visual Basic\Ai\laravel-ai-act-compliance\src\BiasMonitoring\Contracts\CohortParityMetric.php`
- `C:\Users\lopad\Documents\DocLore\Visual Basic\Ai\laravel-ai-act-compliance\src\BiasMonitoring\Services\BiasMonitorService.php`
- `C:\Users\lopad\Documents\DocLore\Visual Basic\Ai\laravel-ai-act-compliance\composer.json`
- `C:\Users\lopad\Documents\DocLore\Visual Basic\Ai\laravel-ai-act-compliance-admin\src\components\Shell.tsx`
- `C:\Users\lopad\Documents\DocLore\Visual Basic\Ai\laravel-ai-act-compliance-admin\src\features\bias\BiasScreen.tsx`
- `docs/v4-platform/ROADMAP-v4-v5-v6.md` (style guide)

## Executive summary

**Semver correction.** Lorenzo's working notes called the next four minor
bumps `v1.1 / v1.2 / v1.3 / v1.4`. That is wrong: both
`padosoft/laravel-ai-act-compliance` and
`padosoft/laravel-ai-act-compliance-admin` shipped **v1.1.3** at v6.0 GA
on 2026-05-14 (composer.json + GitHub release tags confirm). The next
minor release MUST be **v1.2.0**. The four-version sequence covered by
this roadmap is therefore **v1.2 → v1.3 → v1.4 → v1.5**, NOT
v1.1 → v1.4 as the initial verbal sketch implied. The error is harmless
provided we correct it before opening the v1.2 branch — Packagist would
otherwise reject a `v1.1.x` tag against an existing `v1.1.3`.

**v1.2 — CohortParityMetric extension points** (2026-Q3, ~2 weeks).
Goal: turn the current single-implementation
`Padosoft\AiActCompliance\BiasMonitoring\Contracts\CohortParityMetric`
(today a one-method interface returning `array` from `compute(array $context)`)
into a strategy-pattern dispatcher with three reference implementations
(Demographic Parity, Equalized Odds, Calibration) and a registration
surface host apps wire custom cohort dimensions through. Scope: PHP
contracts + reference services + additive `bias_snapshots` columns +
config knobs + admin SPA dropdown extension on the existing `BiasScreen`.
Breaking-change risk: **none — additive only** (the old single-metric
call path remains valid; the new dispatcher resolves to
`DemographicParityMetric` when no strategy is configured). Effort ~10
working days end-to-end including R36 / R39 closure.

**v1.3 — Cohort drift real-time alerting** (2026-Q3, ~2.5 weeks).
Goal: turn the existing 13-week drift series rendered by
`CohortDriftChart` from a passive viz into an active alerting surface
with Slack + Discord webhook channels and an SMTP email fallback.
Scope: new `Padosoft\AiActCompliance\Alerting\` namespace
(channels, dispatcher, throttler, circuit-breaker), drift-detection
hook into `BiasMonitorService::snapshot()`, per-tenant routing config,
NEW admin SPA "Alerts" screen + Settings panel for webhook
configuration. Breaking-change risk: **none — additive only** (alerting
is default-OFF; existing tenants see no behaviour change until a
channel is configured). Effort ~12 working days.

**v1.4 — Regulatory change auto-flagger** (2026-Q4, ~2.5 weeks).
Goal: poll the public EU AI Act amendment feed (EUR-Lex RSS + the
public Council register) and flag rows in `risk_register_entries`
that cite articles which have been amended since last poll. Scope:
new `RegulatoryWatcher\` namespace, `RegulatoryFeedClient`, scheduled
job, `compliance_flags` table, NEW admin SPA "Regulatory Watch" screen
with amendment timeline. Breaking-change risk: **none — additive only**
(feed polling is default-OFF behind `ai-act.regulatory_watch.enabled`;
when disabled the screen renders an empty-state). Effort ~12 working
days.

**v1.5 — DPO multi-org tenant management** (2026-Q4, ~3 weeks).
Goal: allow a single Data Protection Officer account to govern N
tenants (current 1-DPO-per-tenant assumption blocks consultancy
deployments). Scope: `dpo_tenant_memberships` table, `tenant_id`
propagation audit across every v1.x table, cross-tenant aggregated
reporting service, RBAC scope guard, NEW admin SPA "Org Management"
screen + tenant switcher in the topbar. Breaking-change risk: **none
— additive only** (existing single-tenant DPO rows are auto-migrated
into a `dpo_tenant_memberships` row at the upgrade migration; the old
`dpo_accounts.tenant_id` column is retained as a denormalised cache
for backward compat reads). Effort ~15 working days.

**Cumulative wall-clock** for the full v1.2 → v1.5 sequence (backend
+ admin SPA + host integration): **~9 weeks** of focused work, of
which ~1 week is the post-v1.5 AskMyDocs host integration described
under the dedicated section below. All four releases are pure-additive
v1.x bumps — no SemVer-major break is anticipated until a v2.0 cycle
addresses the older API surfaces.

## v1.2 — CohortParityMetric extension points

**Target window:** 2026-Q3 (start window ~2026-07-01 — exact date TBD
once v6.x patches taper).
**Branch:** `feature/v1.2` on `padosoft/laravel-ai-act-compliance`.
**Companion branch:** `feature/v1.2` on
`padosoft/laravel-ai-act-compliance-admin`.

### Motivation

The current contract is intentionally minimal:

```php
// padosoft/laravel-ai-act-compliance/src/BiasMonitoring/Contracts/CohortParityMetric.php
namespace Padosoft\AiActCompliance\BiasMonitoring\Contracts;

interface CohortParityMetric
{
    public function compute(array $context = []): array;
}
```

That works for the v1.1 reference implementation
(`Padosoft\AiActCompliance\BiasMonitoring\Services\BiasMonitorService`
calls a single registered metric) but blocks two real consumer needs
caught in early v1.1 feedback:

1. Different consumers need **different parity definitions**.
   Demographic Parity (P(prediction|cohort) parity) is the default but
   Equalized Odds (TPR + FPR parity) and Calibration parity
   (calibration-by-cohort) are mandated by some regulators for
   high-risk use cases.
2. Consumers need to **register custom cohort dimensions** beyond the
   five baked-in ones (`language`, `gender`, `age_band`, `country`,
   `device_class`). E.g. a fintech consumer needs `credit_band`; a
   medical consumer needs `comorbidity_count`.

### New PHP namespaces and classes

All paths relative to `padosoft/laravel-ai-act-compliance/src/`:

| FQCN | Kind | Purpose |
|---|---|---|
| `BiasMonitoring\Contracts\CohortParityMetric` | interface (extended) | Add `name(): string` + `articleReferences(): array` so the registry can identify and dedupe; keep `compute(array $context = []): MetricResult` (return type tightened, see below). |
| `BiasMonitoring\Contracts\CohortDimensionResolver` | interface (NEW) | `resolveCohortFor(mixed $subject): string` — host apps implement to expose custom dimensions. |
| `BiasMonitoring\Contracts\MetricResult` | final class (NEW) | Value object: `cohortBreakdowns: array<CohortMetric>`, `disparityScore: float`, `worstCohort: ?string`, `articleEvidence: array<string>`, `computedAt: CarbonImmutable`. Replaces the bare `array` return today. |
| `BiasMonitoring\Contracts\CohortMetric` | final class (NEW) | `cohort: string`, `sampleSize: int`, `value: float`, `ciLow: float`, `ciHigh: float`, `flagged: bool`. |
| `BiasMonitoring\Services\BiasMonitorService` | class (REFACTORED) | Replaces hard-coded metric resolution with a `MetricRegistry::resolve($name)` call. Keeps backward-compat: when `bias.default_metric` is null, falls back to `DemographicParityMetric`. |
| `BiasMonitoring\Services\MetricRegistry` | class (NEW) | Singleton registry. `register(string $name, string $fqcn): void`, `resolve(string $name): CohortParityMetric`, `all(): array<string,CohortParityMetric>`. FQCN validated at boot per R23. |
| `BiasMonitoring\Services\DimensionRegistry` | class (NEW) | Singleton registry for custom `CohortDimensionResolver` bindings. `register(string $dimensionKey, CohortDimensionResolver $resolver): void`. |
| `BiasMonitoring\Metrics\DemographicParityMetric` | class (NEW reference impl) | P(prediction|cohort) parity. Default. Article evidence: Art. 10, Art. 15. |
| `BiasMonitoring\Metrics\EqualizedOddsMetric` | class (NEW reference impl) | TPR + FPR parity across cohorts. Article evidence: Art. 10, Art. 15. |
| `BiasMonitoring\Metrics\CalibrationMetric` | class (NEW reference impl) | Calibration-by-cohort (Hosmer-Lemeshow style). Article evidence: Art. 15. |
| `BiasMonitoring\Metrics\AbstractCohortMetric` | abstract class (NEW) | Shared CI bootstrap helper + sample-size weighting helper + cohort-bucketing helper. Reference impls extend this. |
| `BiasMonitoring\Exceptions\UnknownMetricException` | exception (NEW) | Thrown when `MetricRegistry::resolve()` cannot find a name. |
| `BiasMonitoring\Exceptions\OverlappingMetricException` | exception (NEW) | Thrown at boot if two metrics declare overlapping `supports()` predicates — per R23 mutex check. |

### Strategy pattern wiring

`MetricRegistry` is bound as a singleton in
`Padosoft\AiActCompliance\AiActComplianceServiceProvider::register()`.
At boot it validates that every FQCN in `config('ai-act.bias.metrics')`
implements `CohortParityMetric` (per R23) and that no two metrics
declare overlapping `supports()` predicates. Host apps register custom
metrics by either:

1. Adding the FQCN to `config/ai-act.php`:
   ```php
   'bias' => [
       'default_metric' => 'demographic_parity',
       'metrics' => [
           'demographic_parity' => DemographicParityMetric::class,
           'equalized_odds'     => EqualizedOddsMetric::class,
           'calibration'        => CalibrationMetric::class,
           // host-app custom:
           'fintech_credit'     => App\AiAct\Metrics\CreditBandMetric::class,
       ],
   ],
   ```
2. Or calling `app(MetricRegistry::class)->register('name', Fqcn::class)`
   from a service-provider boot method (preferred for third-party
   packages).

### Custom cohort dimension extension points

Host apps wire custom dimensions the same way:

```php
// in HostApp\Providers\AppServiceProvider::boot()
app(DimensionRegistry::class)->register(
    'credit_band',
    new App\AiAct\Resolvers\CreditBandResolver(),
);
```

`BiasMonitorService::snapshot()` reads
`config('ai-act.bias.dimensions')` plus the runtime-registered
dimensions and calls each resolver against every subject in the
snapshot window. The current hard-coded dimension list
(`language`, `gender`, `age_band`, `country`, `device_class`) is
preserved as defaults via internal resolvers.

### Migrations — `bias_snapshots` additive columns

New migration:
`database/migrations/2026_07_01_000000_extend_bias_snapshots_for_metrics.php`

```php
Schema::table('bias_snapshots', function (Blueprint $table) {
    $table->string('metric_name', 64)->default('demographic_parity')->after('cohort_dimension');
    $table->string('metric_version', 16)->default('1.0')->after('metric_name');
    $table->json('article_evidence_json')->nullable()->after('payload_json');
    $table->float('disparity_score', 8, 6)->nullable()->after('article_evidence_json');
    $table->index(['tenant_id', 'metric_name', 'cohort_dimension'], 'idx_bias_snap_tenant_metric_dim');
});
```

Backfill: a one-shot data migration sets `metric_name =
'demographic_parity'` on every pre-v1.2 row (default already applies
for new inserts).

### Config knobs introduced

New section in `config/ai-act.php`:

```php
'bias' => [
    'default_metric' => env('AI_ACT_BIAS_DEFAULT_METRIC', 'demographic_parity'),
    'metrics' => [
        'demographic_parity' => DemographicParityMetric::class,
        'equalized_odds'     => EqualizedOddsMetric::class,
        'calibration'        => CalibrationMetric::class,
    ],
    'dimensions' => [
        // baked-in defaults retained; host apps register additions at runtime
    ],
    'disparity_threshold' => (float) env('AI_ACT_BIAS_DISPARITY_THRESHOLD', 0.05),
    'min_sample_size'     => (int) env('AI_ACT_BIAS_MIN_SAMPLE_SIZE', 30),
],
```

### PHPUnit target: +20 tests

Test plan (target ~+20 PHPUnit):

| Suite | Count | Coverage |
|---|---|---|
| `tests/Unit/BiasMonitoring/Services/MetricRegistryTest.php` | 4 | register / resolve / unknown / overlap rejection (R23). |
| `tests/Unit/BiasMonitoring/Services/DimensionRegistryTest.php` | 3 | register / resolve / fallback to default dimension. |
| `tests/Unit/BiasMonitoring/Metrics/DemographicParityMetricTest.php` | 3 | even cohort / skewed cohort / single-cohort edge case. |
| `tests/Unit/BiasMonitoring/Metrics/EqualizedOddsMetricTest.php` | 3 | TPR parity / FPR parity / both metrics combined. |
| `tests/Unit/BiasMonitoring/Metrics/CalibrationMetricTest.php` | 3 | well-calibrated / mis-calibrated / sparse-bin edge case. |
| `tests/Feature/BiasMonitoring/BiasMonitorServiceMetricDispatchTest.php` | 4 | Service dispatches to configured metric; falls back to default; surfaces `UnknownMetricException` cleanly; respects R30 tenant scope on every cohort query. |

Architecture test additions:
- `tests/Architecture/MetricRegistryFqcnValidatedTest.php` — proves
  the registry rejects non-implementing FQCNs at boot (R23).

### Admin SPA companion (v1.2)

**Companion package:** `padosoft/laravel-ai-act-compliance-admin`
**Scope:** EXTEND existing
`src/features/bias/BiasScreen.tsx`; do NOT create a new screen.

Additions to `BiasScreen.tsx`:

1. New filter group between "Cohort dimension" and "Overall accuracy":
   ```tsx
   <div className="filter-group">
     <label className="filter-label">Parity metric</label>
     <select
       value={metricName}
       onChange={(e) => setMetricName(e.target.value)}
       data-testid="bias-metric-name"
     >
       {METRICS.map((m) => (
         <option key={m.id} value={m.id}>{m.label}</option>
       ))}
     </select>
   </div>
   ```
2. New `METRICS` constant fetched from
   `GET /api/ai-act/bias/metrics` (existing controller extended) —
   no hard-coded list per R18.
3. Cohort dimension dropdown extended to include host-registered
   dimensions returned by the same metadata endpoint
   (`GET /api/ai-act/bias/dimensions`).
4. `MetricResult.articleEvidence` rendered as a badge row above the
   "Accuracy parity per segment" card via
   `<ArticleRef>` (component already exists in
   `src/components/Primitives.tsx`).
5. Subtitle line under `<h1>` extended with the active metric label.

### Vitest +6 / Playwright +1

Vitest additions to
`padosoft/laravel-ai-act-compliance-admin/src/features/bias/`:
- `BiasScreen.metrics.test.tsx` — selects metric → URL state →
  refetch.
- `BiasScreen.metrics.test.tsx` — falls back to default metric on
  metadata-fetch failure (R14 surface-failure UX).
- `BiasScreen.metrics.test.tsx` — renders article-evidence badges.
- `BiasScreen.dimensions.test.tsx` — host-registered dimension is
  listed.
- `BiasScreen.dimensions.test.tsx` — disabled-state preserved for
  empty-data dimensions.
- `BiasScreen.a11y.test.tsx` — new `<select data-testid="bias-metric-name">`
  has accessible name via `<label>` (R15).

Playwright addition (1):
- `e2e/admin/bias-metric-dropdown.spec.ts` — happy path: open Bias
  screen → switch metric → expect refetch + new article evidence
  badges + same testids visible. Failure path covered by a vitest
  unit test, not a Playwright spec (R13).

### v1.2 acceptance gates

- [ ] Backend PR merged with all 3 reference metrics + 20 PHPUnit tests
      green + architecture test green.
- [ ] Admin SPA PR merged with extended `BiasScreen` + 6 Vitest tests
      + 1 Playwright spec green.
- [ ] R36 Copilot loop green on both PRs.
- [ ] R39 RC tag `v1.2.0-rc1` on both repos at the closure-commit SHA,
      then `v1.2.0` final after CI green on `feature/v1.2`.
- [ ] README updated on both repos with "Pluggable bias metrics" hero
      section + Changelog entry (per the per-wave README convention).

## v1.3 — Cohort drift real-time alerting (Slack + Discord + email cascade)

**Target window:** 2026-Q3 (start window ~2026-08-01).
**Branch:** `feature/v1.3` on both repos.

### Motivation

The existing `CohortDriftChart` already detects when a cohort moves
> 0.05 below overall accuracy and surfaces the drift visually
(`BiasScreen.tsx` lines 76–93). Today that signal is **passive**:
the DPO has to log in and notice. AI Act Art. 9 (continuous risk
monitoring) + Art. 15 (accuracy + robustness) effectively require
active notification for high-risk systems. v1.3 wires the drift
detection into a real-time alerting pipeline.

### New PHP namespaces and classes

All paths relative to `padosoft/laravel-ai-act-compliance/src/`:

| FQCN | Kind | Purpose |
|---|---|---|
| `Alerting\Contracts\AlertChannel` | interface (NEW) | `name(): string`, `send(AlertPayload $payload): AlertDispatchResult`. |
| `Alerting\Contracts\AlertPayload` | final class (NEW) | Value object: `severity`, `title`, `body`, `tenant_id`, `evidence_url`, `metric_name`, `cohort`, `articles`. |
| `Alerting\Contracts\AlertDispatchResult` | final class (NEW) | `ok: bool`, `transient: bool`, `httpStatus: ?int`, `errorMessage: ?string`. |
| `Alerting\Channels\SlackWebhookChannel` | class (NEW) | Slack incoming-webhook POST; uses `Http::` (R15-host: no SDK). |
| `Alerting\Channels\DiscordWebhookChannel` | class (NEW) | Discord webhook POST. |
| `Alerting\Channels\EmailFallbackChannel` | class (NEW) | Uses Laravel `Mail::raw()` against configured SMTP; default fallback. |
| `Alerting\Services\AlertDispatcher` | class (NEW) | Resolves channels per tenant, fans out, records each result in `alert_dispatches`. |
| `Alerting\Services\AlertThrottler` | class (NEW) | Per-tenant + per-cohort throttle; default 1 alert / cohort / 60 minutes. |
| `Alerting\Services\CircuitBreaker` | class (NEW) | Trips a channel after N consecutive failures; auto-resets after cool-down. |
| `Alerting\Listeners\BiasDriftDetectedListener` | class (NEW) | Listens for `BiasDriftDetected` event raised by `BiasMonitorService::snapshot()` post-v1.2; builds `AlertPayload` + dispatches. |
| `Alerting\Events\BiasDriftDetected` | event (NEW) | Fired with `tenant_id`, `metric_name`, `cohort`, `disparity_score`, `evidence_url`. |
| `Alerting\Exceptions\WebhookConfigMissingException` | exception (NEW) | Thrown when a channel is enabled but its webhook secret is missing. |

### Drift detection wiring

`BiasMonitorService::snapshot()` (already exists) gains a final step:

```php
if ($this->config('alerting.enabled')
    && $metricResult->disparityScore > $this->config('bias.disparity_threshold')
) {
    event(new BiasDriftDetected(
        tenantId: $this->ctx->current(),
        metricName: $metricResult->metricName,
        cohort: $metricResult->worstCohort,
        disparityScore: $metricResult->disparityScore,
        evidenceUrl: route('ai-act.admin.bias', ['tenant' => $this->ctx->current()]),
    ));
}
```

The listener decides the channel cascade. Default policy:

1. Try Slack channel if `alerting.tenants.<tenant_id>.slack_webhook` is
   set — dispatch + record + return on success.
2. Else try Discord channel if its webhook is set.
3. Always cc-fanout to email if `alerting.tenants.<tenant_id>.email`
   is set, regardless of whether Slack/Discord succeeded — email is
   the auditable trail.
4. Throttle and circuit-breaker apply per-channel, per-tenant.

### Per-tenant routing

New table:
`database/migrations/2026_08_01_000000_create_alert_routes.php`

```php
Schema::create('alert_routes', function (Blueprint $table) {
    $table->id();
    $table->string('tenant_id', 50)->index();
    $table->string('channel', 32);            // 'slack' | 'discord' | 'email'
    $table->text('webhook_url')->nullable();  // encrypted at app layer
    $table->string('email')->nullable();
    $table->json('severity_filter_json')->nullable(); // ['high','critical']
    $table->boolean('enabled')->default(true);
    $table->timestamp('last_success_at')->nullable();
    $table->timestamp('last_failure_at')->nullable();
    $table->unsignedSmallInteger('consecutive_failures')->default(0);
    $table->timestamps();
    $table->unique(['tenant_id', 'channel'], 'uq_alert_routes_tenant_channel');
});

Schema::create('alert_dispatches', function (Blueprint $table) {
    $table->id();
    $table->string('tenant_id', 50)->index();
    $table->foreignId('alert_route_id')->nullable()->constrained()->nullOnDelete();
    $table->string('channel', 32);
    $table->string('severity', 16);
    $table->string('title');
    $table->json('payload_json');
    $table->boolean('ok');
    $table->boolean('transient_failure')->default(false);
    $table->unsignedSmallInteger('http_status')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'created_at'], 'idx_alert_dispatch_tenant_time');
});
```

R30 tenant isolation: all queries scoped via `BelongsToTenant` trait.
R31 mandatory tenant_id on both new tables.

### Retry + circuit-breaker

- Channel failure with HTTP 429 / 5xx / network error =
  `transient: true`. Listener retries up to 3 times with exponential
  backoff (10 / 30 / 60 s).
- After 5 consecutive failures the channel is **tripped**: subsequent
  dispatches skip it for 30 minutes, the email fallback is auto-elevated
  to primary, and an internal `alerting.channel_tripped` audit row is
  written.
- `Http::fake()` covers all happy + retry + trip scenarios in tests.

### Config knobs introduced

```php
// config/ai-act.php
'alerting' => [
    'enabled' => env('AI_ACT_ALERTING_ENABLED', false), // default OFF
    'throttle' => [
        'per_cohort_minutes' => (int) env('AI_ACT_ALERT_THROTTLE_MINUTES', 60),
    ],
    'circuit_breaker' => [
        'failures_to_trip' => (int) env('AI_ACT_ALERT_CB_FAILURES', 5),
        'cooldown_minutes' => (int) env('AI_ACT_ALERT_CB_COOLDOWN', 30),
    ],
    'channels' => [
        'slack'   => SlackWebhookChannel::class,
        'discord' => DiscordWebhookChannel::class,
        'email'   => EmailFallbackChannel::class,
    ],
],
```

Webhook secrets stored in `alert_routes.webhook_url` (encrypted via
Laravel's `Crypt::encryptString()` cast — see Risk Register).

### PHPUnit target: +30 tests

| Suite | Count |
|---|---|
| `tests/Unit/Alerting/Channels/SlackWebhookChannelTest.php` | 5 (happy + 4 failure variants with `Http::fake()`) |
| `tests/Unit/Alerting/Channels/DiscordWebhookChannelTest.php` | 5 |
| `tests/Unit/Alerting/Channels/EmailFallbackChannelTest.php` | 3 |
| `tests/Unit/Alerting/Services/AlertDispatcherTest.php` | 4 (cascade policy / disabled channel / throttle / event recorded). |
| `tests/Unit/Alerting/Services/AlertThrottlerTest.php` | 3 |
| `tests/Unit/Alerting/Services/CircuitBreakerTest.php` | 4 (trip / cooldown / auto-reset / per-channel isolation). |
| `tests/Feature/Alerting/BiasDriftAlertFlowTest.php` | 4 (drift → event → dispatch → record; default-OFF → no event). |
| `tests/Architecture/AlertingTenantScopeTest.php` | 2 (R30 + R31 enforcement). |

### Admin SPA companion (v1.3)

**Scope:** NEW "Alerts" screen + Settings panel webhook config.

New route registered in
`padosoft/laravel-ai-act-compliance-admin/src/components/Shell.tsx`
`ROUTES` array (alongside `/bias`):

```ts
{ key: 'alerts', path: '/alerts', label: 'Alerts', icon: I.Bell, section: 'Operations' },
```

New screen file:
`src/features/alerts/AlertsScreen.tsx` with testid hierarchy per R29:

- `alerts-screen` root (`data-state="ready|loading|error|empty"`).
- Filter bar: `alerts-filter-tenant`, `alerts-filter-channel`,
  `alerts-filter-severity`.
- Table rows: `alerts-row-{id}` with action buttons
  `alerts-row-{id}-retry`, `alerts-row-{id}-detail`.
- Live pill: `alerts-live-pill`.

Settings panel extension at `src/features/settings/SettingsScreen.tsx`
gains a new section "Webhook channels" with:
- `alerts-settings-slack-webhook` input (masked).
- `alerts-settings-discord-webhook` input (masked).
- `alerts-settings-email` input.
- `alerts-settings-test` button (sends a synthetic test alert).
- `alerts-settings-save` button.

Topbar (`Shell.tsx::Topbar`) `alertCount` already exists; v1.3 wires
it to the new `alert_dispatches` count via
`GET /api/ai-act/alerts/count?status=transient_failure`.

### Vitest +10 / Playwright +2

Vitest (10):
- `AlertsScreen.test.tsx` — initial empty state, loaded state, filter
  changes, retry button, error state (5).
- `SettingsScreen.alerts.test.tsx` — webhook save / mask / test
  button / validation (4).
- `Shell.alertCount.test.tsx` — topbar bell count refreshes (1).

Playwright (2):
- `e2e/admin/alerts-list.spec.ts` — happy path + retry flow.
- `e2e/admin/alerts-settings-webhook.spec.ts` — configure Slack
  webhook + send test alert (real-data path; outbound Slack call
  intercepted via `page.route('https://hooks.slack.com/**')` —
  external service per R13).

### v1.3 acceptance gates

- [ ] All 30 PHPUnit tests green; `Http::fake()` covers every channel.
- [ ] Webhook secrets encrypted at rest (verified by an architecture
      test that greps `alert_routes` rows for plaintext URL fragments).
- [ ] Cascade policy spec'd in
      `docs/v4-platform/SPEC-ai-act-v1.3-alert-cascade.md` (NEW file).
- [ ] R36 + R39 closure.

## v1.4 — Regulatory change auto-flagger

**Target window:** 2026-Q4 (start window ~2026-09-15).
**Branch:** `feature/v1.4` on both repos.

### Motivation

AI Act Art. 9 §2 (risk management system "shall be continuously
iterated") + Recital 65 (state-of-the-art changes during the
lifecycle of the system) demand that a high-risk system's risk
register stay current with the regulation itself. Today the
`risk_register_entries.article_references_json` column links each
risk to specific articles, but there is no mechanism to detect that
an article has been **amended** since the risk was assessed.

v1.4 closes this loop by polling the public EU AI Act amendment feeds
and producing `compliance_flags` rows when an article cited in the
register changes.

### New PHP namespaces and classes

| FQCN | Kind | Purpose |
|---|---|---|
| `RegulatoryWatcher\Contracts\RegulatoryFeed` | interface (NEW) | `fetch(): array<RegulatoryAmendment>`. |
| `RegulatoryWatcher\Feeds\EurLexRssFeed` | class (NEW) | Polls `https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32024R1689` change-tracking RSS. Default. |
| `RegulatoryWatcher\Feeds\CouncilRegisterFeed` | class (NEW) | Polls the public Council register endpoint. Secondary source for triangulation. |
| `RegulatoryWatcher\Contracts\RegulatoryAmendment` | final class (NEW) | `articleNumber: string` (e.g. "10", "10.2.a"), `effectiveDate: CarbonImmutable`, `summary: string`, `sourceUrl: string`, `feedName: string`, `rawPayloadHash: string`. |
| `RegulatoryWatcher\Services\RegulatoryFeedClient` | class (NEW) | Orchestrator; runs every configured feed, dedupes by `rawPayloadHash`. |
| `RegulatoryWatcher\Services\SnapshotComparator` | class (NEW) | Compares latest feed pull against `regulatory_snapshots` table; emits net-new amendments. |
| `RegulatoryWatcher\Services\ComplianceFlagger` | class (NEW) | For each net-new amendment finds matching `risk_register_entries` rows (by `article_references_json`) and writes a `compliance_flags` row. |
| `RegulatoryWatcher\Jobs\PollRegulatoryFeedsJob` | job (NEW) | Scheduled hourly. `$tries=3`, backoff `[60, 300, 900]`. |
| `RegulatoryWatcher\Exceptions\FeedUnreachableException` | exception (NEW) | Transient — triggers retry. |
| `RegulatoryWatcher\Exceptions\FeedSchemaChangedException` | exception (NEW) | Permanent — alerts the DPO via v1.3 alerting pipeline; circuit-breaks the feed. |

### Migrations

```php
Schema::create('regulatory_snapshots', function (Blueprint $table) {
    $table->id();
    $table->string('feed_name', 64);
    $table->string('raw_payload_hash', 64); // SHA-256
    $table->json('payload_json');
    $table->timestamp('fetched_at');
    $table->index(['feed_name', 'fetched_at']);
    $table->unique(['feed_name', 'raw_payload_hash'], 'uq_reg_snap_feed_hash');
});

Schema::create('regulatory_amendments', function (Blueprint $table) {
    $table->id();
    $table->string('article_number', 32)->index();
    $table->date('effective_date');
    $table->text('summary');
    $table->string('source_url', 512);
    $table->string('feed_name', 64);
    $table->string('raw_payload_hash', 64);
    $table->timestamps();
    $table->unique(['feed_name', 'raw_payload_hash'], 'uq_reg_amend_feed_hash');
});

Schema::create('compliance_flags', function (Blueprint $table) {
    $table->id();
    $table->string('tenant_id', 50)->index();
    $table->foreignId('risk_register_entry_id')->constrained()->cascadeOnDelete();
    $table->foreignId('regulatory_amendment_id')->constrained()->cascadeOnDelete();
    $table->string('status', 32)->default('open'); // open | acknowledged | resolved
    $table->foreignId('acknowledged_by_user_id')->nullable();
    $table->timestamp('acknowledged_at')->nullable();
    $table->text('resolution_note')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'status'], 'idx_compliance_flags_tenant_status');
});
```

### Snapshot comparison flow

1. `PollRegulatoryFeedsJob` runs hourly via scheduler entry in
   `routes/console.php` of the package's test app, mirrored in host
   integration:
   ```php
   $schedule->job(new PollRegulatoryFeedsJob)
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping()
            ->when(fn () => config('ai-act.regulatory_watch.enabled'));
   ```
2. For each configured feed, fetch → SHA-256 hash → `INSERT IGNORE`
   into `regulatory_snapshots`.
3. Parse → `RegulatoryAmendment[]` → `INSERT IGNORE` into
   `regulatory_amendments`.
4. For each net-new amendment, `ComplianceFlagger` finds rows in
   `risk_register_entries` whose `article_references_json` contains
   the amended article number and creates a `compliance_flags` row
   (per tenant, deduped on `(risk_register_entry_id,
   regulatory_amendment_id)`).
5. v1.3 alerting fires a `BiasDriftDetected`-equivalent event
   `RegulatoryAmendmentDetected` — same channel cascade.

### Config knobs introduced

```php
'regulatory_watch' => [
    'enabled' => env('AI_ACT_REG_WATCH_ENABLED', false), // default OFF
    'feeds' => [
        'eurlex'   => EurLexRssFeed::class,
        'council'  => CouncilRegisterFeed::class,
    ],
    'poll_minutes'   => (int) env('AI_ACT_REG_WATCH_POLL_MIN', 60),
    'http_timeout'   => (int) env('AI_ACT_REG_WATCH_HTTP_TIMEOUT', 30),
    'alert_severity' => env('AI_ACT_REG_WATCH_ALERT_SEVERITY', 'high'),
],
```

### PHPUnit target: +25 tests

| Suite | Count |
|---|---|
| `tests/Unit/RegulatoryWatcher/Feeds/EurLexRssFeedTest.php` | 5 (happy / empty / 304 / 5xx / malformed schema). |
| `tests/Unit/RegulatoryWatcher/Feeds/CouncilRegisterFeedTest.php` | 4 |
| `tests/Unit/RegulatoryWatcher/Services/SnapshotComparatorTest.php` | 4 |
| `tests/Unit/RegulatoryWatcher/Services/ComplianceFlaggerTest.php` | 5 (article exact match / sub-article / no match / dedupe / cross-tenant isolation per R30). |
| `tests/Feature/RegulatoryWatcher/PollRegulatoryFeedsJobTest.php` | 5 (full happy path with `Http::fake()` + scheduling + feature-flag OFF). |
| `tests/Architecture/RegulatoryWatcherTenantScopeTest.php` | 2 |

### Admin SPA companion (v1.4)

**Scope:** NEW "Regulatory Watch" screen + amendment timeline.

New route in `Shell.tsx::ROUTES`:
```ts
{ key: 'regulatory-watch', path: '/regulatory-watch', label: 'Regulatory Watch', icon: I.Newspaper, section: 'Governance' },
```

New screen `src/features/regulatory/RegulatoryWatchScreen.tsx`:

- Header KPIs: `regwatch-kpi-amendments-30d`, `regwatch-kpi-open-flags`,
  `regwatch-kpi-resolved-flags`.
- Timeline view: vertical timeline of amendments, each rendered as
  `regwatch-amendment-{id}` card with article number, effective
  date, summary, source-url link.
- Flags table below timeline: `regwatch-flag-row-{id}` with action
  buttons `regwatch-flag-row-{id}-acknowledge`,
  `regwatch-flag-row-{id}-resolve` (opens resolution-note modal).
- Filter bar: `regwatch-filter-article`, `regwatch-filter-status`,
  `regwatch-filter-date-range`.

### Vitest +8 / Playwright +2

Vitest (8):
- `RegulatoryWatchScreen.test.tsx` — render / empty / loading / error
  (4).
- `RegulatoryWatchScreen.flags.test.tsx` — acknowledge / resolve flow
  (2).
- `RegulatoryWatchScreen.filters.test.tsx` — filter combinations (2).

Playwright (2):
- `e2e/admin/regulatory-watch-timeline.spec.ts` — happy path.
- `e2e/admin/regulatory-watch-flag-resolve.spec.ts` — full
  acknowledge → resolve flow with note.

### v1.4 acceptance gates

- [ ] EUR-Lex feed parser handles current real-world payload (record
      a fixture, replay via `Http::fake()`).
- [ ] Schema-drift guard: feed reports `FeedSchemaChangedException`
      cleanly when payload shape changes.
- [ ] R36 + R39 closure on both repos.

## v1.5 — DPO multi-org tenant management

**Target window:** 2026-Q4 (start window ~2026-11-01).
**Branch:** `feature/v1.5` on both repos.

### Motivation

The current implementation assumes a 1:1 DPO ↔ tenant mapping. Two
real consumer needs break this:

1. **DPO consultancy firms** — a single human Giulia Amalfi
   (the placeholder DPO in `Shell.tsx` line 192) is the DPO for ten
   different tenants (each customer of the consultancy). She needs a
   single login and a cross-tenant view.
2. **Group companies with shared DPO** — one DPO governs N legal
   entities, each a separate AskMyDocs / AI-act-compliance tenant.

### New PHP namespaces and classes

| FQCN | Kind | Purpose |
|---|---|---|
| `Dpo\Models\DpoAccount` | model (REFACTORED) | The existing `dpo_accounts` model loses its hard `tenant_id` semantics; `tenant_id` becomes a denormalised "primary tenant" cache for backward compat. |
| `Dpo\Models\DpoTenantMembership` | model (NEW) | Pivot row linking a DPO to N tenants with a role (`primary` / `delegate` / `read_only`). |
| `Dpo\Services\DpoTenantResolver` | class (NEW) | Resolves the active tenant for a logged-in DPO request: reads `X-Tenant-Id` header or a session attribute, validates membership, sets `TenantContext`. |
| `Dpo\Services\CrossTenantAggregator` | class (NEW) | Builds aggregated KPIs across the DPO's tenant set (open DSAR count, open incidents, high-severity risks, drift alerts). |
| `Dpo\Http\Middleware\ResolveDpoTenant` | middleware (NEW) | Runs ahead of `ResolveTenant` host middleware; sets `TenantContext` from validated membership. |
| `Dpo\Policies\DpoTenantMembershipPolicy` | policy (NEW) | RBAC: only `primary` DPO can add/remove memberships; `delegate` and `read_only` see but don't mutate. |
| `Dpo\Exceptions\TenantMembershipMissingException` | exception (NEW) | Thrown when a DPO requests a tenant they don't belong to — surfaces as 403. |

### Migrations

```php
Schema::create('dpo_tenant_memberships', function (Blueprint $table) {
    $table->id();
    $table->foreignId('dpo_account_id')->constrained()->cascadeOnDelete();
    $table->string('tenant_id', 50);
    $table->string('role', 32); // 'primary' | 'delegate' | 'read_only'
    $table->timestamp('granted_at')->useCurrent();
    $table->foreignId('granted_by_user_id')->nullable();
    $table->timestamp('revoked_at')->nullable();
    $table->timestamps();
    $table->unique(['dpo_account_id', 'tenant_id'], 'uq_dpo_tenant_membership');
    $table->index(['tenant_id'], 'idx_dpo_tenant_membership_tenant');
});
```

Data migration: for every existing `dpo_accounts` row, insert a
`dpo_tenant_memberships` row with `role='primary'` linking that DPO to
its current `tenant_id`. Keep `dpo_accounts.tenant_id` as a cached
denormalisation (read-only after migration; new code MUST query the
membership table).

### tenant_id propagation audit across all v1.x tables

A v1.5 prerequisite is a full re-audit of every table introduced in
v1.0 → v1.4 to ensure R30 + R31 hold for cross-tenant reporting:

| Table | tenant_id column? | Composite unique start? | Notes |
|---|---|---|---|
| `dsar_requests` | yes | yes | OK |
| `consent_records` | yes | yes | OK |
| `risk_register_entries` | yes | yes | OK |
| `fria_assessments` | yes | yes | OK |
| `incidents` | yes | yes | OK |
| `bias_snapshots` | yes | yes (post-v1.2) | OK |
| `alert_routes` (v1.3) | yes | yes | OK |
| `alert_dispatches` (v1.3) | yes | n/a | OK |
| `regulatory_snapshots` (v1.4) | **no** (global, by design) | n/a | Documented exception — feed snapshots are tenant-agnostic. |
| `regulatory_amendments` (v1.4) | **no** (global) | n/a | Documented exception. |
| `compliance_flags` (v1.4) | yes | yes | OK |
| `dpo_accounts` | yes (denormalised) | replaced by `dpo_tenant_memberships` | Audit row. |
| `dpo_tenant_memberships` (v1.5) | yes | yes | New. |

The audit lands as `tests/Architecture/V15TenantIdAuditTest.php` —
enumerates every v1.x table and asserts the contract.

### Cross-tenant aggregated reports with RBAC scoping

`CrossTenantAggregator` exposes a single method:

```php
public function aggregate(int $dpoAccountId, array $kpiKeys): array
```

It:
1. Looks up the DPO's active (non-revoked) memberships.
2. For each KPI, runs a single query scoped to `WHERE tenant_id IN
   (...)` against the DPO's tenant set.
3. Returns a per-tenant breakdown plus a totals row.
4. Honours `role`: `read_only` memberships are included in
   aggregation; `delegate` membership can drill in but not mutate;
   `primary` can do anything.

New REST endpoint `GET /api/ai-act/dpo/cross-tenant/kpis` returns
the aggregation. Default response shape stays additive — old
single-tenant clients continue to work via the legacy
`/api/ai-act/dashboard/kpis` endpoint, unchanged.

### Config knobs introduced

```php
'dpo' => [
    'multi_org' => [
        'enabled' => env('AI_ACT_DPO_MULTI_ORG', false), // default OFF
        'tenant_header' => env('AI_ACT_DPO_TENANT_HEADER', 'X-Tenant-Id'),
        'session_key'   => env('AI_ACT_DPO_TENANT_SESSION_KEY', 'ai_act.active_tenant'),
    ],
],
```

When `multi_org.enabled = false` the package behaves identically to
v1.4 — backward-compat guaranteed.

### PHPUnit target: +35 tests

| Suite | Count |
|---|---|
| `tests/Unit/Dpo/Models/DpoTenantMembershipTest.php` | 4 |
| `tests/Unit/Dpo/Services/DpoTenantResolverTest.php` | 6 (header / session / fallback / unknown / revoked / role check). |
| `tests/Unit/Dpo/Services/CrossTenantAggregatorTest.php` | 6 |
| `tests/Unit/Dpo/Policies/DpoTenantMembershipPolicyTest.php` | 4 |
| `tests/Feature/Dpo/CrossTenantKpisEndpointTest.php` | 5 (happy / no membership / read-only / delegate / primary). |
| `tests/Feature/Dpo/TenantSwitcherFlowTest.php` | 4 |
| `tests/Feature/Dpo/BackwardCompatSingleTenantTest.php` | 4 (multi-org OFF behaves identically to v1.4). |
| `tests/Architecture/V15TenantIdAuditTest.php` | 1 (enumerates every v1.x table). |
| `tests/Architecture/DpoMultiOrgFeatureFlagTest.php` | 1 (flag-OFF disables every new code path). |

### Admin SPA companion (v1.5)

**Scope:** NEW "Org Management" screen + tenant switcher in topbar.

#### Topbar tenant switcher

Extend `Shell.tsx::Topbar` with a new pill between `<crumbs>` and
`<topbar-spacer>`:

```tsx
<button
  type="button"
  className="tenant-switcher"
  onClick={() => setSwitcherOpen(true)}
  data-testid="topbar-tenant-switcher"
>
  <I.Building size={13} />
  <span>{activeTenant.label}</span>
  <I.ChevronDown size={11} />
</button>
```

A modal listing the DPO's tenants opens on click; testid
`tenant-switcher-modal`. Each entry is
`tenant-switcher-option-{tenant_id}` and a "Select" button
`tenant-switcher-option-{tenant_id}-select`. Selection writes the
session key + updates `TenantContext` via a POST to
`/api/ai-act/dpo/switch-tenant`, then SPA refetches via TanStack
Query `invalidateQueries`.

#### Org Management screen

New route in `Shell.tsx::ROUTES`:
```ts
{ key: 'org-management', path: '/org-management', label: 'Org Management', icon: I.Building2, section: 'Governance' },
```

New screen `src/features/org-management/OrgManagementScreen.tsx`:

- Tenant table: `org-mgmt-tenant-row-{id}` with columns name / role
  / granted-at / open DSAR / open incidents / open risks.
- Add membership: `org-mgmt-add-membership` button → modal with
  tenant picker + role selector. Only `primary` DPOs see the button
  (RBAC enforced backend-side too — R21 atomic invariant).
- Revoke: `org-mgmt-tenant-row-{id}-revoke` button → confirm dialog.
- Cross-tenant KPI dashboard above the table: 4 KPI cards
  `org-mgmt-kpi-dsar`, `org-mgmt-kpi-incidents`, `org-mgmt-kpi-risks`,
  `org-mgmt-kpi-drift-alerts`. Each card renders the per-tenant
  breakdown via a small sparkline on hover.

#### Vitest +12 / Playwright +3

Vitest (12):
- `OrgManagementScreen.test.tsx` — render / empty / loading / error
  (4).
- `OrgManagementScreen.crud.test.tsx` — add / revoke + role
  enforcement (4).
- `OrgManagementScreen.kpi.test.tsx` — aggregated KPI per tenant (2).
- `Shell.tenantSwitcher.test.tsx` — switcher opens / selects /
  invalidates (2).

Playwright (3):
- `e2e/admin/org-management-list.spec.ts` — happy path.
- `e2e/admin/org-management-rbac.spec.ts` — delegate cannot revoke;
  read-only cannot see Add button.
- `e2e/admin/tenant-switcher-flow.spec.ts` — switch tenant from
  topbar → screens refetch → KPIs reflect new tenant.

### v1.5 acceptance gates

- [ ] All 35 PHPUnit tests green; backward-compat suite green.
- [ ] R30 audit test green for every v1.x table.
- [ ] R21 atomic invariant verified for membership grant/revoke
      (lock + write inside same transaction).
- [ ] Migration tested on a non-trivial fixture (≥ 5 DPOs × ≥ 3
      tenants each).
- [ ] R36 + R39 closure.

## AskMyDocs host integration (post-v1.5)

**Branch:** `feature/vN.M` on `lopadova/AskMyDocs` (vN.M to be
decided based on the active major-cycle when v1.5 ships — likely
v6.x patch or v7.x depending on cadence).
**Wall-clock:** ~1 week.

### composer.json pin bump

```diff
-    "padosoft/laravel-ai-act-compliance": "^1.1",
-    "padosoft/laravel-ai-act-compliance-admin": "^1.1",
+    "padosoft/laravel-ai-act-compliance": "^1.5",
+    "padosoft/laravel-ai-act-compliance-admin": "^1.5",
```

### `bootstrap/app.php` deltas

Three additive middleware registrations:

```php
->withMiddleware(function (Middleware $middleware) {
    // existing host middleware...
    $middleware->web(append: [
        \Padosoft\AiActCompliance\Dpo\Http\Middleware\ResolveDpoTenant::class,
    ]);
    $middleware->api(append: [
        \Padosoft\AiActCompliance\Dpo\Http\Middleware\ResolveDpoTenant::class,
    ]);
})
```

### `routes/api.php` deltas

Cross-mount the package's new endpoints under
`/api/ai-act/dpo/*` and `/api/ai-act/alerts/*` per the v4.6 cross-mount
pattern already established for `pii-redactor-admin` /
`eval-harness-ui`.

### New host services / observers / config knobs

- `app/Services/AiAct/HostAlertFanoutObserver.php` — listens to
  `BiasDriftDetected` + `RegulatoryAmendmentDetected` events and
  optionally also writes to host's existing `admin_command_audits`
  trail (host-side compliance forensic record).
- `config/services.php` adds an entry for `slack.fallback_webhook`
  (host's own webhook as last-ditch fallback).
- `app/Console/Kernel.php` (or `routes/console.php`) wires
  `PollRegulatoryFeedsJob` into the host scheduler at `03:00`
  alongside the existing 03:10–03:40 KB jobs (R32 scheduling block).

### Test deltas (host repo)

Target: **~+30 PHPUnit + ~+5 Playwright** on
`lopadova/AskMyDocs`.

PHPUnit (~30):
- `tests/Feature/AiAct/DpoTenantSwitchingHostFlowTest.php` — full
  host-mounted flow (10).
- `tests/Feature/AiAct/RegulatoryAmendmentHostFanoutTest.php` —
  package event → host observer → host audit trail (8).
- `tests/Feature/AiAct/BiasDriftHostFanoutTest.php` — same shape
  (6).
- `tests/Architecture/AiActCrossMountTest.php` — verifies cross-mount
  routes are registered + reachable (3).
- `tests/Architecture/HostObserverWiringTest.php` (3).

Playwright (~5) under `frontend/e2e/admin/ai-act/`:
- `host-ai-act-tenant-switcher.spec.ts` — host-mounted switcher
  works inside AskMyDocs SPA.
- `host-ai-act-alerts-screen.spec.ts` — Alerts screen reachable
  from host nav.
- `host-ai-act-regulatory-watch.spec.ts` — Regulatory Watch screen
  reachable + timeline renders.
- `host-ai-act-bias-metric-dropdown.spec.ts` — host-mounted Bias
  screen exposes v1.2 metric dropdown.
- `host-ai-act-org-management-rbac.spec.ts` — RBAC scoping survives
  the cross-mount layer.

### Host integration acceptance gates

- [ ] `composer.json` pin bumped; CI matrix passes against pinned
      v1.5.
- [ ] All 30 PHPUnit + 5 Playwright green.
- [ ] Cross-mount works without iframe (v4.4 pattern preserved).
- [ ] R37: integration branch → main one merge at end-of-cycle;
      `vN.M.0` GA tag.

## Release sequencing (R36 + R39 flow)

Both packages follow the standing Padosoft / AskMyDocs convention.
**Each package's `feature/v1.x` branch lives independently** — Lorenzo
opens four parallel sequences:

```
laravel-ai-act-compliance:        feature/v1.2 → feature/v1.3 → feature/v1.4 → feature/v1.5
laravel-ai-act-compliance-admin:  feature/v1.2 → feature/v1.3 → feature/v1.4 → feature/v1.5
                                      ↑               ↑               ↑               ↑
                                  paired R36      paired R36      paired R36      paired R36
```

### Per-version flow (the 9-step R36 ritual)

For each of v1.2 / v1.3 / v1.4 / v1.5, for each repo:

1. Branch from `main` of the respective repo to `feature/v1.x`.
2. Implement backend (or admin) sub-PR(s); local PHPUnit / Vitest /
   Playwright all green.
3. `gh pr create --reviewer copilot-pull-request-reviewer ...` to
   `feature/v1.x` (per R36).
4. Wait for CI green (60–180 s).
5. Wait for Copilot review (2–15 min after PR open) — **do not skip**.
6. Address review comments locally; push fix.
7. Wait for CI green AND Copilot re-review.
8. Loop until 0 must-fix + all checks green.
9. Merge sub-PR into `feature/v1.x`.

After all sub-PRs land on `feature/v1.x`:

10. Refresh README hero + Changelog (per the per-wave convention).
11. Capture closure-commit SHA: `git rev-parse origin/feature/v1.x`.
12. Tag `v1.x.0-rc1` on that exact SHA via
    `gh release create v1.x.0-rc1 --target $SHA --prerelease`.
13. After ≥ 24 h of soak + acceptance gates green, open the final PR
    merging `feature/v1.x` → `main` (R37 once-per-minor).
14. Tag `v1.x.0` (final, not prerelease).

### Cross-package coordination

The admin SPA companion at every version **depends on** the matching
backend version having shipped its new endpoints. Therefore:

- Open the backend `feature/v1.x` PR first.
- Once backend `v1.x.0-rc1` is tagged on
  `padosoft/laravel-ai-act-compliance`, open the admin
  `feature/v1.x` PR with the matching pin in `package.json`
  (`"padosoft-laravel-ai-act-compliance-api": "^1.x"` if a JS
  client mirror exists, or a doc reference if not).
- Tag both `v1.x.0-rc1` within the same day for easier audit.
- Tag both `v1.x.0` final in the same docs-refresh batch.

### Estimated cumulative wall-clock

| Version | Backend | Admin SPA | R36 loop overhead | Subtotal |
|---|---|---|---|---|
| v1.2 | 6 d | 2 d | ~2 d | **~10 d** |
| v1.3 | 7 d | 3 d | ~2 d | **~12 d** |
| v1.4 | 7 d | 3 d | ~2 d | **~12 d** |
| v1.5 | 9 d | 4 d | ~2 d | **~15 d** |
| Host integration | n/a | n/a | ~5 d incl. R36 | **~5 d** |
| **Total** | **29 d** | **12 d** | **~13 d** | **~54 d (~9 calendar weeks)** |

Caveat: numbers assume zero major regressions on EU AI Act feed
schema (v1.4) and zero major Copilot review surprises (v1.3 + v1.5
have the largest test surfaces). Realistic worst-case stretch:
~12 calendar weeks.

## Risk register

Five risks scored qualitatively (P = probability, I = impact):

### Risk 1 — EUR-Lex / Council register schema instability (v1.4)

- **P:** Medium — the EU regularly tweaks the public-feed payload
  schema; the RSS-on-EUR-Lex path is documented but not
  rigidly versioned.
- **I:** High — a silent schema change breaks
  `RegulatoryFeedClient::fetch()` and the auto-flagger goes dark
  exactly when the regulation changes (the worst possible moment).
- **Mitigation:**
  - `FeedSchemaChangedException` raised by parsers on unexpected
    payload shape; auto-trips circuit breaker; fires alert via v1.3
    pipeline so the DPO knows the watcher is degraded.
  - Two independent feeds (`EurLexRssFeed` +
    `CouncilRegisterFeed`); triangulate.
  - Fixture-replay test suite refreshed every Q to confirm parsers
    still match real-world payloads.
  - Document the manual-override path in the v1.4 README: a DPO
    can post a `regulatory_amendments` row by hand if the watcher
    is degraded.

### Risk 2 — Multi-tenant FK cascade pitfalls (v1.5)

- **P:** Medium — `dpo_tenant_memberships` is the first cross-tenant
  pivot in the package; cascade behaviour on revoke / delete needs
  care.
- **I:** High — wrong cascade either leaks audit history across
  tenants (GDPR catastrophe per R30) or silently drops compliance
  evidence on revoke.
- **Mitigation:**
  - Revoke is **soft** (`revoked_at` timestamp), never hard delete.
  - `tests/Architecture/V15TenantIdAuditTest.php` enumerates every
    table and asserts the FK + cascade behaviour.
  - `CrossTenantAggregator` queries always go through
    `forTenant($id)` scope per R30; architecture test enforces it.
  - Migration ships a verification script that counts pre- and
    post-migration row counts per tenant and aborts on mismatch.

### Risk 3 — Bias metric backward-compat regression (v1.2)

- **P:** Low — the v1.2 dispatcher falls back to
  `DemographicParityMetric` when no strategy is configured.
- **I:** Medium — a regression could subtly shift cohort accuracy
  numbers, eroding DPO trust.
- **Mitigation:**
  - `tests/Feature/BiasMonitoring/V11BackwardCompatTest.php` —
    pins a fixture cohort dataset and asserts v1.1 ↔ v1.2 numerical
    parity on every metric to 6 decimal places.
  - `MetricRegistry` exposes `current_default()` so the admin SPA
    badge can announce which metric is active.
  - Migration is gated behind a `--dry-run` flag for staging
    validation.

### Risk 4 — Alerting webhook secret storage (v1.3)

- **P:** Medium — webhooks are credential-equivalent (anyone with
  the URL can post to the channel); leaking them via log lines or
  unencrypted DB columns is a high-blast-radius mistake.
- **I:** High — a leaked Slack/Discord webhook lets attackers
  spoof regulatory alerts on the DPO's primary alerting channel.
- **Mitigation:**
  - `alert_routes.webhook_url` column uses Laravel's
    `Crypt::encryptString()` cast; never serialised to logs.
  - `LogTailService` (host) has a redaction rule for the
    `webhooks.slack.com` / `discord.com/api/webhooks` substrings
    per R32 + the existing pii-redactor v1.2 boundary coverage.
  - Architecture test `tests/Architecture/WebhookSecretNotLoggedTest.php`
    greps test logs for plaintext webhook fragments and fails
    the build.
  - Admin SPA mask UI per R29 — webhook inputs render
    `••••••••XYZ` after first save.

### Risk 5 — Copilot review cadence on padosoft/* repos (cross-version)

- **P:** High — Lorenzo flagged on 2026-04-29 that padosoft/* repos
  shipped without `--reviewer copilot-pull-request-reviewer` once
  before. The risk is procedural, not technical.
- **I:** Medium — missing review = missing R36 enforcement = higher
  chance of must-fix slipping into a v1.x.0 release.
- **Mitigation:**
  - Every PR opening command in this roadmap MUST include
    `--reviewer copilot-pull-request-reviewer` per R36 step 3.
  - On padosoft/* repos, where `gh pr edit --add-reviewer` returns
    422 "not a collaborator" (per memory entry), use
    `gh api POST /issues/<N>/comments` with body `"@copilot review"`
    to re-request reviews.
  - The 5-minute-wait-after-last-push rule (per memory entry,
    2026-05-02 askmydocs-pro PR #1 lesson) applies to every push
    on every PR.
  - Local critic loop (per memory entry, 2026-05-13) — my
    pr-review-toolkit agents + copilot-cli — runs BEFORE every
    push starting v1.2.

## Out of scope (explicitly NOT in this roadmap)

The following items are explicitly **NOT** in scope for v1.2 → v1.5.
An over-eager implementor should NOT pick them up without a separate
ADR scoping them into a future version.

1. **PDF-rendered FRIA exports.** v1.x keeps FRIA assessments as
   structured JSON only; PDF render is a v2.0 candidate.
2. **OAuth2 federation across DPOs.** v1.5 keeps the DPO login model
   as a single Sanctum-backed user table with `dpo_account` rows on
   top. Cross-org SSO is a v2.0 candidate.
3. **GraphQL surface.** REST + JSON only across v1.x. GraphQL has
   been periodically requested by integrators but adds no v1.x
   user-visible value.
4. **Real-time SSE / WebSocket push of alert events to the SPA.**
   v1.3 alerts go to webhook channels (Slack / Discord / email);
   the SPA polls via TanStack Query at the default 30s tick. A
   server-push channel into the SPA is a v2.0 candidate.
5. **Custom alerting channels beyond Slack / Discord / email.**
   PagerDuty, Opsgenie, Microsoft Teams, etc. are NOT in v1.3.
   The `AlertChannel` interface is open; community contributors
   can publish channel packages, but v1.3 ships exactly the three.
6. **Cross-tenant DSAR resolution UI.** v1.5 cross-tenant
   aggregation is **read-only**. Bulk DSAR resolution across N
   tenants is a v2.0 candidate.
7. **New regulatory feeds beyond EUR-Lex + Council register.**
   National-level transposition feeds (Italian, German, French
   gazettes) are NOT in v1.4. Two feeds only.
8. **Metric historical migration.** v1.2 introduces new metrics
   but does **NOT** retroactively recompute pre-v1.2 bias snapshots
   with the new metrics. Historical rows carry
   `metric_name='demographic_parity'` permanently.
9. **Admin SPA i18n.** All new screens (Alerts, Regulatory Watch,
   Org Management) ship in English only. Italian + multi-language
   support is a v2.0 candidate.
10. **Mobile-responsive layout for the admin SPA.** Desktop-first
    only. Mobile is parked.
11. **Multi-region webhook deployment.** v1.3 webhooks are routed
    from the package's primary Laravel app; geographic redundancy
    is a v2.0 candidate.
12. **Custom bias metric scoring beyond the registry.** v1.2 opens
    the registry to host apps but **does NOT** ship a low-code
    metric authoring UI in the admin SPA. Defining a custom metric
    still requires PHP.

---

**End of roadmap.** This document is the single source of truth for
the v1.2 → v1.5 cycle on `padosoft/laravel-ai-act-compliance` +
`padosoft/laravel-ai-act-compliance-admin`. Any deviation requires
an ADR. Next action when v1.2 cycle starts: open `feature/v1.2`
on both repos, capture closure SHA discipline from day one.
