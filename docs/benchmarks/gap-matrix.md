# W00.01 — AskMyDocs/WeKnora gap inventory

Status: `INVENTORY_PARTIAL`

This file is the reproducibility index for the benchmark workstream. It records what was actually rechecked in the local AskMyDocs workspace; it does not turn a static package snapshot into a runtime result.

| Gap area | AskMyDocs evidence | WeKnora evidence | Reproducible scenario | Status |
|---|---|---|---|---|
| Tenant/ACL retrieval | `app/Scopes/AccessScopeScope.php`, `app/Policies/KnowledgeDocumentPolicy.php`, `tests/Feature/Rbac/DocumentAclTest.php` | snapshot `4df7ccbb4f650082331452a92b52854f8922da2a` | same project membership with explicit deny and source ACL restriction | local AskMyDocs tests pass; WeKnora runtime not verified |
| Agent identity/context | `app/Agent/AgentExecutionContext.php`, `tests/Feature/Agent/AgentRunTransportTest.php` | not in AskMyDocs workspace | enqueue under tenant A, consume under tenant B | local AskMyDocs tests pass |
| TOTP/recovery | `app/Services/Auth/TwoFactorService.php`, `tests/Feature/Api/Auth/TwoFactorTest.php` | not applicable to RAG comparison | enrollment, replay, recovery reuse, disable and rate limit | local Testbench/SQLite only |
| Graph navigation | `tests/Architecture/GraphExecutorNotAdoptedTest.php`, `app/Console/Commands/KbWikiNavigateCommand.php` | snapshot only | graph hop must re-check tenant/ACL and bound depth/tokens | target runtime not verified |
| Tables/tabular review | `app/Http/Controllers/Api/Admin/TabularReviewController.php` and v8.40 baseline | snapshot only | preserve headers/units/cell locators through extraction and citation | runtime benchmark not executed |
| Multimodal/OCR | package pipeline and OCR commands in current tree | snapshot only | low-quality OCR must expose uncertainty, not fabricate text | fixture/holdout corpus missing |
| Evidence/abstention | `app/Evidence/`, KB citation and refusal tests | snapshot only | unsupported answer is abstention with provenance | comparative measurement missing |

## Source and limits

- AskMyDocs snapshot used by the supplied package: `63e9916b990dc3fb76b8b84083c72a489b5926f5`.
- AskMyDocs reconciled implementation commit: `2f3484e211d0e85b105b4ca55573174c88a0e6ec`.
- WeKnora snapshot supplied by the package: `4df7ccbb4f650082331452a92b52854f8922da2a`.
- No customer document, credential, private corpus or production endpoint is included here.
- W00.01 remains incomplete until the 120-query corpus, 40 adversarial cases and 20 tool/action cases exist with provenance and train/holdout split.
