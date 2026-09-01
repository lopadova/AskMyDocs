# ADR 0028 — Source ACL mirroring and ingest-time provenance (design)

- **Status:** Proposed
- **Date:** 2026-08-25
- **Cycle:** target v8.32 → v8.34 (phased)
- **Builds on:** the connector framework (`askmydocs-connector-base` ^1.4), the
  capability-interface pattern (`SupportsCredentialForm`, `SupportsFolderDiscovery`),
  R30 tenant scoping, R33 scope-allowlist-in-SQL, the Auto-Wiki
  human > auto > raw reranker firewall, `knowledge_document_acl`.

## Context

Eight connectors ingest into the KB. Every source they read from has its own
permission model — Drive sharing, Confluence space restrictions, Jira issue
security levels, Notion page permissions, OneDrive/SharePoint scopes. None of
it survives ingestion:

```php
// ConnectorIngestionContract::dispatchIngestion()
projectKey, relativePath, disk, title, metadata, mimeType, tenantId
```

There is no permission argument, so a document shared with three people in
Drive becomes, on ingest, a document readable by everyone with the project.
Nothing in the code is wrong; the contract simply has nowhere to put the fact.
This is the failure that made *oversharing* the headline problem of enterprise
assistant rollouts, and mirroring source permissions is the one capability the
category leader's moat is actually built on.

A second gap runs alongside it. Seven connectors ingest content written by
someone inside the organisation. **IMAP ingests content written by anyone who
can send an email.** That mail becomes a document, the document becomes chunks,
the chunks are retrieved as grounding, and the same platform exposes MCP tools
that an agent can call. That is a complete indirect-injection chain —
attacker-controlled text reaching a tool-calling context — with no boundary
anywhere between the two ends.

The two problems share a cause: **the ingestion contract records what a
document *is* and not where its authority comes from.** They should therefore
be designed together and shipped separately.

Two pieces of groundwork already exist and should be reused rather than
reinvented:

- The Auto-Wiki tier already enforces a trust ordering (human `accepted` >
  `auto` > raw) in the reranker, so trust tiers are an established idea here.
  Provenance itself has no home yet, though: the `provenance` columns that do
  exist — `kb_edges.provenance` (how an edge was derived) and the
  `chat_log_provenance` table (which answer tokens came from which chunk) —
  record different facts. `knowledge_documents` has no provenance column, so
  Phase 1 adds one rather than reusing anything.
- R33 just pushed the membership scope allowlist into SQL, establishing the
  pattern for enforcing per-document authorization inside the query rather than
  after it — including on the retrieval hot path, which never calls the policy.
  Source ACLs plug into exactly that seam.

## Decision

### 1. The contract gains two optional capabilities, never a required argument

Following the existing capability-interface pattern, both additions are
opt-in interfaces with default behaviour in `BaseConnector`:

```php
interface SupportsSourceAcl      { public function readAcl(string $remoteId): SourceAccess; }
interface DeclaresProvenance     { public function provenanceTier(): ProvenanceTier; }
```

A connector that implements neither interface behaves exactly as it does
today. This is non-negotiable: eight connectors and a **public template**
consume this contract, and a breaking signature would strand every
third-party connector built on it.

> **Correction (v8.33 implementation).** This decision originally read
> "`dispatchIngestion()` gains one optional `?SourceAccess $access = null`
> parameter", on the assumption that an optional trailing parameter is
> additive. **It is not, and the ADR was wrong.** PHP rejects an
> implementation declaring fewer parameters than its interface, optional or
> not:
>
> ```
> Fatal error: Declaration of Impl::f(string $a): void must be compatible
> with C::f(string $a, ?string $b = null): void
> ```
>
> The reasoning had connectors in mind, and connectors are *callers* of
> `ConnectorIngestionContract` — they would indeed have been fine. Hosts are
> *implementers*, and a host binding its own implementation would have
> fatalled at class-declaration time on upgrade, before a line of its own
> code ran. The very compatibility this section calls non-negotiable would
> have been broken by the mechanism chosen to preserve it.
>
> The contract is therefore **unchanged**. The DTO travels inside
> `$metadata` under `SourceAccess::METADATA_KEY`, written by
> `BaseConnector::withSourceAccess()` so no call site handles the key by
> hand, and rebuilt with `SourceAccess::fromArray()`. `$metadata` is already
> the extension channel for per-document facts of this kind.

### 2. `SourceAccess` carries principals, not decisions

```php
final class SourceAccess {
    /** @var list<SourcePrincipal> */ public array $principals;   // type + external id + effect
    public bool $inheritsFromParent;                              // folder/space inheritance
    public bool $complete;                                        // false = source truncated the list
}
```

The connector reports **what the source said**. It never decides who may read;
that mapping is the host's job and depends on directory state the connector
does not have.

### 3. Unmapped principals fail closed — this is the load-bearing decision

External principals are resolved to internal subjects by a `PrincipalResolver`
(verified email match, directory link, group mapping). Resolution will
sometimes fail: an external collaborator, a group the directory does not know,
a truncated ACL (`complete === false`).

**A document whose access could not be fully mapped must not fall back to
project-wide visibility.** That fallback is precisely the bug this ADR exists
to fix, and it is the tempting shortcut. Instead the document is stored with
`access_scope = 'restricted-unmapped'` and is readable only by the connector
installation's owner until an operator triages it.

This will make some documents invisible that are visible today. That is the
intended direction of the change and must be stated in the release notes,
with an admin surface (**Admin → Connectors → Unmapped access**) listing what
is quarantined and why.

### 4. Storage reuses `knowledge_document_acl`

The table already has `subject_type` / `subject_id` / `permission` / `effect`
with deny-wins semantics. Two additions:

- `subject_type = 'external'` for an unresolved source principal, so the row
  records the fact rather than discarding it.
- an `origin` column (`manual` | `source-mirror`) so a re-sync can reconcile
  mirrored rows **without** destroying ACLs an operator set by hand.

Revocation matters as much as granting: when a source removes a share, the
reconciliation pass must delete the mirrored row. A mirror that only ever adds
permissions is a slow leak.

### 5. Enforcement extends R33, then moves to the PDP

Phase A extends `AccessScopeScope` with the mirrored allow-rows, reusing the
`ScopeAllowlistSql` approach — authorization inside the query, so retrieval
gets it for free.

Phase B hands the decision to `padosoft/laravel-iam-server`. The natural shape
of "user → group → folder → document" is ReBAC, which the PDP already does, and
it buys citable decision ids on every retrieval — the prerequisite for grounded
answer receipts. **AskMyDocs currently depends on none of the IAM suite**; this
is the point where that changes, and it should be a deliberate decision rather
than a side effect.

### 6. Provenance is assigned by the connector, and ships in two phases

```php
enum ProvenanceTier { TrustedInternal; UntrustedExternal; MachineGenerated; }
```

- **Phase 1 — label only.** The connector declares the tier; ingestion stores
  it; the admin surfaces it. No enforcement. This is cheap (a column and a
  contract method) and immediately answers *"how much of our corpus is
  externally authored?"* — a question nobody can answer today. Labels without
  enforcement are still worth shipping: they make the enforcement testable
  before it exists.
- **Phase 2 — enforcement.** An `UntrustedExternal` chunk may be **quoted** in
  an answer but must never influence a tool call. The guardrails tool firewall
  consults the provenance of the chunks in context; a tool invocation whose
  arguments derive from untrusted grounding needs a policy allowing it, or a
  human confirmation.

**Provenance is orthogonal to the Auto-Wiki firewall and must not be collapsed
into it.** That firewall ranks by *curation tier* (has a human vouched for
this?); provenance records *authorship origin* (who wrote it, and do we trust
them?). A human-accepted page summarising an external email is `accepted` and
`UntrustedExternal` at once, and both facts matter.

## Decision rationale (ADR-style)

- **Why optional interfaces rather than a richer required DTO.** The contract
  is public API for a connector template other people build on. Additive
  capability interfaces are the pattern the framework already uses for
  credential forms and folder discovery; a breaking change here costs far more
  than the small amount of branching it saves.
- **Why the connector declares provenance rather than the host inferring it.**
  Only the connector knows whether a mailbox is an internal distribution list
  or a public contact address. Inference at the host would be a heuristic over
  a fact the fetcher already had.
- **Why fail-closed despite the support cost.** The alternative — treat
  unmapped as public — is indistinguishable from today's behaviour, which means
  shipping the machinery without the fix. If the quarantine proves too noisy,
  the answer is a better resolver, not a laxer default.
- **Why not do 13 and 14 in one release.** They share the contract change and
  nothing else. Provenance labelling is days; ACL mirroring plus principal
  resolution plus reconciliation plus a triage UI is a cycle. Coupling them
  would delay the cheap one behind the expensive one.

## Risks and open questions

| Risk | Mitigation |
|---|---|
| Principal resolution is the hard, unglamorous core | Ship the resolver with an explicit "unresolved" bucket and a triage screen; do not hide failures |
| Fail-closed hides documents users expect to see | Release-note it loudly, default the whole feature OFF per house convention, ship the admin surface in the same release |
| Source permissions drift after ingest | Reconciliation on every sync, including deletions; treat a revoked share as a revocation |
| Per-document ACL rows at corpus scale | Index `(knowledge_document_id, subject_type, subject_id)` exists; measure before adding the PDP round trip; Phase A stays in SQL |
| Not every source exposes usable ACLs | `SupportsSourceAcl` is opt-in; a connector that cannot read permissions declares so and its documents are labelled as unmirrored rather than silently trusted |

**Open:** whether `access_scope` (existing column) is the right home for
`restricted-unmapped` or whether it deserves its own column — depends on what
`access_scope` currently means in practice, which this design has not audited.

## Rollout

1. **v8.32** — contract interfaces + `ProvenanceTier` + column + connector
   declarations + admin read-out. Phase 1 of 14. Default ON (labels are inert).
2. **v8.33** — `SourceAccess`, resolver, mirrored ACL rows, reconciliation,
   triage UI, `AccessScopeScope` extension. Feature 13, default OFF.
3. **v8.34** — provenance enforcement in the tool firewall. Phase 2 of 14,
   default OFF.

   > **Superseded 2026-08-30: the firewall now ships ON.** It shipped OFF as
   > specified on 2026-08-29, and the product owner then decided to reverse the
   > default. Recorded here rather than only in config, because a default that
   > contradicts its ADR is exactly the kind of drift this file exists to stop.
   >
   > The ADR's reasoning was about behaviour change on upgrade, and it stands:
   > a deployment already ingesting email will find that turns grounded in a
   > message stop being able to act. What it under-weighted is that a security
   > control shipping OFF protects nobody until somebody remembers to switch it
   > on — and the chain it closes is not hypothetical. It is three ordinary
   > facts about this product (IMAP ingests what anyone can send; ingested
   > content becomes grounding; the same platform exposes tools to the model)
   > composing into an injection path with no boundary in between.
   >
   > The cost is bounded and the failure direction is safe: the answer is still
   > produced from the same context with the same citations, only the tools are
   > withheld, and the turn declines to act rather than acting on a stranger's
   > instructions. `KB_PROVENANCE_TOOL_FIREWALL=false` restores the previous
   > behaviour exactly, and both states are covered by tests (R43).

Each phase updates `README.md` and the doc site in the same PR, per house rule.
