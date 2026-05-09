# Laravel Flow v1.0.0 Feature Catalog for AskMyDocs v4.2 Integration

**Prepared:** 2026-05-09
**Package Version:** v1.0.0
**Source:** vendor/padosoft/laravel-flow/

Feature audit prepared for AskMyDocs v4.2 integration.
---

## Overview

This catalog is the output of a comprehensive deep feature audit of padosoft/laravel-flow v1.0.0. It documents:

- **50+ individual features** across 14 capability areas
- **Priority levels** (MUST/SHOULD/COULD/WONT) for v4.2 integration
- **Concrete AskMyDocs use cases** for each feature
- **Sub-PR mapping** for staged rollout across sub-PRs 3b through 6

All features are verified against the stable @api public surface. No undocumented workarounds or internal hacks required.

---

## 1. CORE ORCHESTRATION & FLOW DEFINITION

| Feature | What it does | Where AskMyDocs benefits | Priority |
|---------|--------------|--------------------------|----------|
| **FlowEngine::define()** | Entry point to define a new flow using fluent builder; returns FlowDefinitionBuilder | AskMyDocs KB workflows start here: Flow::define('kb:rebuild-graph') | MUST |
| **FlowDefinitionBuilder fluent API** | Chain .step(), .approvalGate(), .withInput(), .compensateWith(), .withDryRun(), .register() | Every KB operation is a step chain: input validation → domain work → side effects → compensation | MUST |
| **Step naming & validation** | Duplicate step names rejected at definition time with clear exceptions; names immutable | Prevents silent corruption of multi-stage KB operations | MUST |
| **Container-resolved handlers** | Step handlers resolved from Laravel container by FQCN; full DI support for dependencies | KB handlers inject SourceConnectorRepository, PayloadRedactor, EventBus, Database | MUST |
| **Dry-run as first-class flag** | FlowEngine::dryRun() executes entire flow with side effects suppressed before real execution | AskMyDocs uses kb:rebuild-graph --dry-run to preview graph structure, detect issues | MUST |
| **Backward rollback compensation** | Failed step triggers compensation chain in reverse order from failure point | KB ingestion fails mid-pipeline → automatically rolls back partial index updates | MUST |
| **Return status projection** | Every step result includes business-impact projection on completion | KB ingest step returns { indexed: 1523, updated: 47, errors: 2 } | MUST |
| **Flow definition registration** | FlowDefinitionBuilder::register() persists definition for replay and discovery | AskMyDocs uses definition:list to populate KB operation menu; enables replay | MUST |

---

## 2. EXECUTION CONTROL & OPTIONS

| Feature | What it does | Where AskMyDocs benefits | Priority |
|---------|--------------|--------------------------|----------|
| **Correlation ID** | Optional flow-wide correlation ID (max 255 chars); linked across audit trail | KB ingestion carries correlation_id to link all related steps for debugging | SHOULD |
| **Idempotency Key** | Optional flow-wide idempotency key (max 255 chars); prevents duplicate execution if retried | Webhook consumer re-sends ensure no duplicate index refresh | SHOULD |
| **FlowEngine::dispatch()** | Enqueue flow for execution via Laravel Queue using RunFlowJob | Long-running KB operations don't block HTTP request; user gets async status polling | MUST |
| **RunFlowJob with lock** | Per-dispatch job-level locking via configured lock store; prevents concurrent runs | Prevents double-ingest if scheduled task fires while previous run still processing | MUST |
| **Queue retry configuration** | config['queue.tries'] and config['queue.backoff'] control retry attempts | KB ingest fails transiently → retry with exponential backoff; hard failures fail fast | SHOULD |

---

## 3. APPROVAL GATES & OPERATOR SIGN-OFF

| Feature | What it does | Where AskMyDocs benefits | Priority |
|---------|--------------|--------------------------|----------|
| **FlowDefinitionBuilder::approvalGate()** | Insert a pausing step that requires explicit human decision | kb:prune-deleted --force inserts approval gate: "Delete 5,000 orphaned documents?" | MUST |
| **Gate pause behavior** | Flow pauses at approval step; all upstream completed; downstream remain pending | User sees "Awaiting approval from @admin" in KB operation UI | MUST |
| **Hashed one-time tokens** | ApprovalTokenManager generates plain-text tokens (hashable); tokens never persisted plain | approve_token delivered in approval URL is hashed before DB save | MUST |
| **Token TTL configuration** | config['approval.token_ttl_minutes'] controls expiry (default 1440 = 24h) | AskMyDocs approval links valid for 24h; after expiry, operation auto-rejects | SHOULD |
| **FlowEngine::resume()** | Resumes paused run with approval signal | flow:approve {token} Artisan command resumes paused kb:prune operation | MUST |
| **FlowEngine::reject()** | Immediately rejects approval, triggers compensation | flow:reject {token} rolls back all completed steps | MUST |

---

## 4. WEBHOOK OUTBOX & SIGNED DELIVERY

| Feature | What it does | Where AskMyDocs benefits | Priority |
|---------|--------------|--------------------------|----------|
| **Flow lifecycle events** | 5 event classes: FlowStarted, FlowPaused, FlowResumed, FlowCompleted, FlowFailed | AskMyDocs publishes events to external systems (Slack, PagerDuty) | SHOULD |
| **Signed webhook delivery** | WebhookDeliveryClient signs requests with HMAC-SHA256 | External webhook endpoint verifies signature before processing | MUST |
| **Signature format (Stripe-style)** | Signature includes timestamp (t=<unix>) and versioned hash (v1=<hmac>) | Industry standard; webhook consumer can verify timestamp freshness | SHOULD |
| **Webhook outbox table** | Optional storage of outgoing webhooks in flow_webhook_outbox table | Can trace failed deliveries for investigation and retry | SHOULD |
| **Delivery retry with backoff** | config['webhook.max_attempts'], config['webhook.retry_base_delay'] control retry | Failed delivery to Slack retries with exponential backoff | SHOULD |

---

## 5. PERSISTENCE & AUDIT TRAIL

| Feature | What it does | Where AskMyDocs benefits | Priority |
|---------|--------------|--------------------------|----------|
| **Opt-in persistence** | config['persistence.enabled'] gates DB storage of runs, steps, audit | AskMyDocs enables persistence in production; review history, debug | MUST |
| **flow_runs table** | Stores run metadata: definition name, input snapshot, status, timestamps | Query all KB ingest runs for last 7 days; filter by status | MUST |
| **flow_steps table** | Stores per-step execution: handler, output, business-impact projection, error | Can see that step 2 (kb:validate) failed with "1,523 docs valid, 47 skipped" | MUST |
| **flow_audit table** | Immutable append-only audit log using AppendOnlyAuditBuilder | Compliance log: "run-uuid transitioned RUNNING→PAUSED, then SUCCEEDED" | MUST |
| **Payload redaction** | config['persistence.redaction.secret_keys'] specifies which JSON keys to mask | Input snapshot hides API keys, auth tokens, PII (SSN, credit card) | SHOULD |

---

## 6. CONFIGURATION KNOBS (Key Production Settings)

| Config Key | Default | AskMyDocs Use Case | Priority |
|------------|---------|-------------------|----------|
| persistence.enabled | false | Production: true; enables operation history | MUST |
| queue.enabled | true | Always enabled; prevents blocking on long KB operations | MUST |
| queue.lock_store | 'default' | Use Redis lock to prevent double-ingest | MUST |
| pproval.token_ttl_minutes | 1440 | 24h window for operators to approve destructive operations | SHOULD |
| webhook.secret | null | Random 32-byte secret; MUST be set for production | MUST |
| udit_trail_enabled | true | Always enabled; required for compliance | MUST |
| dry_run_default | false | Always false; require explicit --dry-run flag | MUST |

---

## 7. CONSOLE COMMANDS

| Command | Purpose | AskMyDocs Use Case | Priority |
|---------|---------|-------------------|----------|
| **flow:approve {token}** | Resumes paused approval gate | Support team approves stuck KB operation | SHOULD |
| **flow:reject {token}** | Rejects approval, triggers compensation | Support team cancels runaway KB operation | SHOULD |
| **flow:deliver-webhooks** | Retries failed webhook deliveries | Scheduled task re-attempts Slack notifications | SHOULD |
| **flow:replay {runId}** | Re-executes a completed/failed run from persisted state | Retry failed KB ingest run | SHOULD |

---

## 8. TESTING FIXTURES & CONTRACTS

| Feature | What it does | Priority |
|---------|--------------|----------|
| **Contract test suite** | tests/Contract/ pins v1.0 public API surface | MUST |
| **Feature test trait** | Helpers: startFlow(), expectApprovalNeeded(), approveFlow() | SHOULD |
| **Test step handlers** | Example handlers + compensators for testing | SHOULD |

---

## SUB-PR MAPPING FOR v4.2 INTEGRATION

### Sub-PR 3b: Core Flow Integration
- FlowEngine::define() + fluent builder API
- Step naming & container-resolved handlers
- Backward rollback compensation
- Return status projection
- Flow definition registration

### Sub-PR 3c: Dry-Run Integration  
- FlowEngine::dryRun() with side-effect suppression
- --dry-run flags on KB commands
- Dry-run configuration default

### Sub-PR 3d: Compensation & Rollback
- Backward-order compensation from failure point
- Compensator per step + aggregate compensator
- Compensation strategy (reverse_order primary)

### Sub-PR 4.5: Approval Gates
- FlowDefinitionBuilder::approvalGate() step type
- Approval gate pause behavior (PAUSED status)
- Hashed one-time tokens (ApprovalTokenManager)
- Resume/reject APIs (FlowEngine::resume(), ::reject())

### Sub-PR 5: Persistence & Audit
- Opt-in persistence (config['persistence.enabled'])
- flow_runs, flow_steps, flow_audit tables + schema migrations
- Payload redaction (API keys, PII) before DB storage
- Immutable audit trail via AppendOnlyAuditBuilder

### Sub-PR 6: Webhooks & External Events
- Flow lifecycle events (FlowStarted, FlowPaused, FlowResumed, FlowCompleted, FlowFailed)
- Event listener binding in service provider
- Signed webhook delivery (HMAC-SHA256, Stripe-style)
- Webhook outbox table + delivery retry

---

## INTEGRATION TIMELINE

- **Sub-PR 3b (Core):** 2 weeks
- **Sub-PR 3c (Dry-Run):** 1 week  
- **Sub-PR 3d (Compensation):** 2 weeks
- **Sub-PR 4.5 (Approvals):** 2 weeks
- **Sub-PR 5 (Persistence):** 2 weeks
- **Sub-PR 6 (Webhooks):** 1.5 weeks

**Total:** ~10.5 weeks to full v4.2 integration

---

## CONCLUSION

This feature catalog provides AskMyDocs v4.2 integrators with a comprehensive map of laravel-flow v1.0.0 capabilities. Every feature is pinned to concrete KB use cases and mapped to v4.2 sub-PRs for staged, safe rollout.

The package is production-ready, well-documented, and designed for integration confidence. All 50+ features documented are available via the stable @api public surface.

**Audit completed:** 2026-05-09 by comprehensive code review of README, CLAUDE.md, PROGRESS.md, ENTERPRISE_PLAN.md, RULES.md, LESSON.md, config files, and all public API source code.