# Inventory

Questa tabella documenta l'analisi file-per-file della `.claude` sorgente.

Legenda:

- `kept-generalized`: tenuto e riscritto in forma riusabile
- `excluded-project-specific`: escluso per forte coupling al progetto sorgente
- `excluded-tooling-specific`: escluso per dipendenza da tool/package/workflow non riusabili come baseline

## Root

| Sorgente | Decisione | Destinazione | Note |
|---|---|---|---|
| `CLAUDE.md` | kept-generalized | `CLAUDE.md` + stack overlays | Rimosse entita', package e workflow interni |
| `instructions/testing-safety.md` | kept-generalized | `instructions/testing-safety.md` | Tolti esempi con DB specifico del progetto |
| `commands/pagespeed-review.md` | kept-generalized | `commands/pagespeed-review.md` | Reso indipendente da Laravel Mix/Gescat |
| `skills/review-pr-comments/SKILL.md` | kept-generalized | `skills/review-pr-comments/SKILL.md` | Reso GitHub-agnostic e meno dipendente da `gh` details |
| `rules/rule-code-structure.md` | kept-generalized | `rules/rule-code-structure.md` | Conservati early return, chiarezza, commenti mirati |
| `rules/rule-early-return.md` | kept-generalized | `rules/rule-early-return.md` | Portabile ovunque |
| `rules/rule-frontend-js-css.md` | kept-generalized | `rules/rule-frontend-js-css.md` | Best practice web generiche |
| `rules/rule-naming-conventions.md` | kept-generalized | `rules/rule-naming-conventions.md` | Tolte convenzioni interne |
| `rules/rule-no-debug-in-commits.md` | kept-generalized | `rules/rule-no-debug-in-commits.md` | Portabile ovunque |
| `rules/rule-no-git-worktree.md` | kept-generalized | `rules/rule-no-git-worktree.md` | Tenuta come policy opzionale aziendale |
| `rules/rule-pr-workflow.md` | kept-generalized | `rules/rule-pr-workflow.md` | Resa repo-agnostic |
| `rules/rule-type-hints.md` | kept-generalized | `rules/rule-type-hints.md` | Portabile per PHP/Laravel |
| `settings.json` | excluded-tooling-specific | — | Hook locale per graphify, non baseline |
| `plans/*` | excluded-project-specific | — | Materiale operativo locale |
| `projects/*` | excluded-project-specific | — | Stato locale del progetto sorgente |

## Laravel

| Sorgente | Decisione | Destinazione | Note |
|---|---|---|---|
| `agents/admin-interface-architect.md` | kept-generalized | `laravel/agents/admin-interface-architect.md` | Tolti riferimenti a UI library e repo specifico |
| `commands/create-setting.md` | kept-generalized | `laravel/commands/create-setting.md` | Generalizzato su settings persistiti in DB/config |
| `commands/(nuovo) create-job.md` | kept-generalized | `laravel/commands/create-job.md` | Pattern queue/job Laravel 13 separato dal service |
| `commands/domain-scaffold.md` | kept-generalized | `laravel/commands/domain-scaffold.md` | Generalizzato per Laravel domain-oriented |
| `commands/domain-service.md` | kept-generalized | `laravel/commands/domain-service.md` | Reso neutro rispetto a helper interni |
| `laravel/PATTERN-ADOPTION.md` | kept-generalized | `laravel/PATTERN-ADOPTION.md` | Documento aggiuntivo per dichiarare i pattern realmente dominanti e la baseline Laravel 13 |
| `commands/settings-panel.md` | excluded-tooling-specific | — | Dipende da UI component proprietario |
| `commands/kitt-setup.md` | excluded-project-specific | — | KITT e' feature interna |
| `commands/crud-model-setup.md` | excluded-tooling-specific | — | Basato su comando `crud:activate` proprietario |
| `commands/domain-command.md` | excluded-tooling-specific | — | Molto legato a trait, settings e queue policy interne |
| `skills/admin-interface-backend/SKILL.md` | kept-generalized | `laravel/skills/admin-interface-backend/SKILL.md` | Resa self-contained |
| `skills/admin-interface-component-audit/SKILL.md` | kept-generalized | `laravel/skills/admin-interface-component-audit/SKILL.md` | Audit riuso vs creazione |
| `skills/admin-interface-frontend/SKILL.md` | kept-generalized | `laravel/skills/admin-interface-frontend/SKILL.md` | Rimossa dipendenza da componenti specifici |
| `skills/create-admin-interface/SKILL.md` | kept-generalized | `laravel/skills/create-admin-interface/SKILL.md` | Orchestratore generale |
| `skills/create-controller/SKILL.md` | kept-generalized | `laravel/skills/create-controller/SKILL.md` | Lifecycle controller/request/service/view |
| `skills/create-crud-backend/SKILL.md` | kept-generalized | `laravel/skills/create-crud-backend/SKILL.md` | CRUD admin generico |
| `skills/create-filesystem-helpers/SKILL.md` | kept-generalized | `laravel/skills/create-filesystem-helpers/SKILL.md` | Portabile su storage/disks |
| `skills/(nuova) create-api-endpoint/SKILL.md` | kept-generalized | `laravel/skills/create-api-endpoint/SKILL.md` | Endpoint API moderno con FormRequest, DTO, Service e JsonResource |
| `skills/create-service/SKILL.md` | kept-generalized | `laravel/skills/create-service/SKILL.md` | Service + DTO neutrali |
| `skills/create-test/SKILL.md` | kept-generalized | `laravel/skills/create-test/SKILL.md` | Unit/feature tests generici |
| `skills/create-event-service/SKILL.md` | excluded-project-specific | — | Event system interno |
| `skills/create-import-type/SKILL.md` | excluded-project-specific | — | Import batch interno |
| `skills/create-frontend-component/SKILL.md` | excluded-project-specific | — | Naming e file layout fortemente custom |
| `rules/rule-admin-ajax-pattern.md` | kept-generalized | `laravel/rules/rule-admin-ajax-pattern.md` | Mantenuto come pattern admin asincrono |
| `rules/rule-admin-interface-structure.md` | kept-generalized | `laravel/rules/rule-admin-interface-structure.md` | Architettura admin complessa |
| `rules/rule-database-design.md` | kept-generalized | `laravel/rules/rule-database-design.md` | Portabile su RDBMS |
| `rules/rule-domain-assignment.md` | kept-generalized | `laravel/rules/rule-domain-assignment.md` | Boundaries di dominio |
| `rules/rule-domain-patterns.md` | kept-generalized | `laravel/rules/rule-domain-patterns.md` | Convenzioni Domain-oriented |
| `rules/rule-exception-handling.md` | kept-generalized | `laravel/rules/rule-exception-handling.md` | Linee guida Laravel-oriented |
| `rules/(nuova) laravel13-defaults` | kept-generalized | `laravel/rules/rule-laravel13-defaults.md` | Baseline tecnologica Laravel 13, PHPUnit, Pint, Larastan |
| `rules/rule-logging-security.md` | kept-generalized | `laravel/rules/rule-logging-security.md` | Rimossi package specifici |
| `rules/rule-naming-directories.md` | kept-generalized | `laravel/rules/rule-naming-directories.md` | Coerenza file/path |
| `rules/rule-query-optimization.md` | kept-generalized | `laravel/rules/rule-query-optimization.md` | Portabile su Eloquent/Query Builder |
| `rules/(nuova) form-request-dto-service-flow` | kept-generalized | `laravel/rules/rule-form-request-dto-service-flow.md` | Aggiunta per rendere esplicito il pattern dominante emerso dall'analisi |
| `rules/rule-service-job-dto-queue.md` | kept-generalized | `laravel/rules/rule-service-job-dto-queue.md` | Pattern asincrono riusabile |
| `rules/rule-storage-stream.md` | kept-generalized | `laravel/rules/rule-storage-stream.md` | Streaming file portabile |
| `rules/rule-cache-recalculation.md` | excluded-project-specific | — | Denormalizzazioni e cache interne |
| `rules/rule-eventservice-trigger.md` | excluded-project-specific | — | EventService interno |
| `rules/rule-failed-jobs.md` | excluded-tooling-specific | — | Workflow DLQ troppo dipendente dall'infrastruttura attuale |
| `rules/rule-import-batch.md` | excluded-project-specific | — | Import framework interno |
| `rules/rule-model-events.md` | excluded-tooling-specific | — | CmsAdmin event system |
| `rules/rule-presenter-pattern.md` | excluded-tooling-specific | — | Package presenter dedicato |
| `rules/rule-querybuilder-macros.md` | excluded-project-specific | — | Macros custom del progetto |
| `rules/rules-query-builder.md` | excluded-project-specific | — | Standard Enterprise costruito attorno a BaseQuery custom |
| `rules/rule-ui-design-tokens.md` | excluded-tooling-specific | — | Dipende da token/UI lib proprietaria |
| `rules/rule-ambiente-enum.md` | excluded-project-specific | — | Enum di business del catalogo |
| `rules/rule-articoli-visibility-macro.md` | excluded-project-specific | — | Macro articoli del catalogo |
| `rules/rule-fe-tenant-architecture.md` | excluded-project-specific | — | Architettura tenant del frontend sorgente |
| `rules/rule-submodule-isset.md` | excluded-project-specific | — | Sub-repo frontend specifici |
| `rules/rule-sync-ai-instructions.md` | excluded-project-specific | — | Workflow multi-agent e subrepo locale |

## Playwright

| Sorgente | Decisione | Destinazione | Note |
|---|---|---|---|
| `agents/playwright-enterprise-tester.md` | kept-generalized | `playwright/agents/playwright-enterprise-tester.md` | Snellito e reso multi-repo |
| `commands/playwright-tester.md` | kept-generalized | `playwright/commands/playwright-tester.md` | Wrapper generico |
| `skills/playwright-enterprise-tester/**/*` | kept-generalized | `playwright/skills/playwright-enterprise-tester/SKILL.md` | Consolidato in skill autonoma, senza asset Gescat-specifici |
| `rules/rule-chain-pagespeed-after-frontend-tests.md` | kept-generalized | `playwright/rules/rule-chain-pagespeed-after-frontend-tests.md` | Portabile |
| `rules/rule-ci-test-failure-analysis.md` | kept-generalized | `playwright/rules/rule-ci-test-failure-analysis.md` | Generalizzato su CI artifacts/log analysis |
| `rules/rule-frontend-testability-contracts.md` | kept-generalized | `playwright/rules/rule-frontend-testability-contracts.md` | Reso framework-agnostic |
| reference/scripts/templates Playwright | excluded-tooling-specific | — | Consolidati in documentazione, non copiati uno a uno |
