# Conservazione della produzione e riconciliazioni

La copia esatta di develop avrebbe eliminato 109 file introdotti solo in main e arretrato altri 25 file modificati esclusivamente in produzione. Il risultato conserva tutti questi 134 file esattamente come nello snapshot di main.

## Comportamenti conservati

- Autenticazione 2FA e relativo rate limit; valutazione IAM in modalità shadow.
- Contratti ActionIntent, canonicalizzazione e ricevute di approvazione delle azioni.
- Export e import Wiki, richieste asincrone, candidati di importazione e tool MCP.
- Routine di manutenzione Wiki con API generica e webhook disabilitati per impostazione predefinita.
- Colonne Vision nella tabular review e presentazione delle citazioni immagine.
- Binding del principal MCP e contabilizzazione fisica di handshake/traffico legacy.
- Aggiornamenti di produzione delle dipendenze: restano le versioni più recenti compatibili già bloccate sui due rami; npm ricostruisce inoltre le dipendenze annidate necessarie.

## Risoluzioni dei 14 conflitti

| File | Scelta applicata |
|---|---|
| `CLAUDE.md` | Mantiene i nuovi paragrafi di develop e il riferimento alla routine Wiki di main. |
| `app/Agent/Tools/AgentServerToolRunner.php` | Mantiene il dispatch fra runtime MCP connector/legacy di develop e la prenotazione/contabilizzazione delle richieste fisiche di main. |
| `app/Agent/Tools/AgentToolRegistry.php` | Mantiene contesto tenant e annotazioni di develop, più le stime di traffico MCP del pack di produzione. |
| `app/Mcp/Client/McpHandshakeService.php` | Conserva entrambe le eccezioni usate dal runtime e la compatibilità col pack 2.0. |
| `app/Providers/AppServiceProvider.php` | Unisce registrazioni IAM/ActionRegistry, FinOps, routine Wiki, gate Realtime e rate limit 2FA/triage. |
| `composer.json` | Unisce le dipendenze runtime, conserva le routine, il floor del pack 2.0.1 e i nuovi pacchetti di develop. |
| `composer.lock` | Unisce i pacchetti e conserva la versione più recente fra quelle sorgenti; verifica dei vincoli con Composer e installazione pulita. |
| `package.json` | Conserva gli aggiornamenti npm di main e aggiunge i nuovi pacchetti di develop. |
| `package-lock.json` | Ricostruisce il lock combinato con npm, comprese le dipendenze Radix annidate. |
| `frontend/src/features/admin/tabular-reviews/TabularReviewsList.tsx` | Mantiene SelectField/localizzazione di develop e la modalità Vision con etichetta della citazione di main. |
| `tests/Architecture/RouteExposureTest.php` | Conserva sia il contratto delle routine di main sia la route MCP project-scoped di develop. |
| `tests/Architecture/TenantReadScopeTest.php` | Conserva i modelli Wiki di main e i modelli shadow di develop nella verifica degli scope. |
| `tests/Feature/V7/PackageRoundTripIntegrationTest.php` | Conserva lo scripting esplicito di initialize necessario al pack 2.0. |
| `tests/TestCase.php` | Registra sia le routine sia il provider Realtime, mantenendo gli override sicuri del test harness. |

## File di produzione conservati byte per byte

- `.superpowers/sdd/askmydocs-enterprise-roadmap/progress.md`
- `app/Actions/ActionApprovalException.php`
- `app/Actions/ActionApprovalReceipt.php`
- `app/Actions/ActionApprovalService.php`
- `app/Actions/ActionCanonicalizer.php`
- `app/Actions/ActionIntent.php`
- `app/Actions/ActionRegistry.php`
- `app/Actions/PendingActionApproval.php`
- `app/Authorization/IamPdp.php`
- `app/Authorization/IamShadowEvaluator.php`
- `app/Authorization/IamShadowReport.php`
- `app/Authorization/SpatieIamPdp.php`
- `app/Console/Commands/KbExportWikiCommand.php`
- `app/Console/Commands/KbImportWikiCommand.php`
- `app/Console/Commands/KbWikiRoutineCommand.php`
- `app/Console/Commands/PruneWikiExportsCommand.php`
- `app/Exceptions/KbWikiImportRateLimitedException.php`
- `app/Http/Controllers/Api/Admin/KbWikiExportController.php`
- `app/Http/Controllers/Api/Admin/KbWikiImportController.php`
- `app/Http/Controllers/Api/Admin/KbWikiRoutineController.php`
- `app/Http/Controllers/Api/Auth/TwoFactorController.php`
- `app/Http/Controllers/Api/Widget/WidgetSessionController.php`
- `app/Http/Middleware/EnforceMcpScope.php`
- `app/Http/Requests/Admin/Kb/StoreKbWikiExportRequest.php`
- `app/Http/Requests/Admin/Kb/StoreKbWikiImportRequest.php`
- `app/Http/Requests/Auth/TwoFactorRequest.php`
- `app/Jobs/ExecuteKbWikiExportJob.php`
- `app/Mcp/Client/McpToolInvocation.php`
- `app/Mcp/Client/McpToolInvocationFailed.php`
- `app/Mcp/Client/ToolInvoker.php`
- `app/Mcp/Tools/KbCreateExportTool.php`
- `app/Mcp/Tools/KbGetExportTool.php`
- `app/Mcp/Tools/KbImportWikiTool.php`
- `app/Mcp/Tools/KbWikiRoutineStatusTool.php`
- `app/Models/KbWikiExportRequest.php`
- `app/Models/KbWikiImportCandidate.php`
- `app/Models/User.php`
- `app/Routines/WikiMaintenanceRoutineGate.php`
- `app/Routines/WikiMaintenanceRoutineTarget.php`
- `app/Routines/WikiRoutineService.php`
- `app/Services/Auth/TwoFactorService.php`
- `app/Services/Kb/Export/KbWikiExportRequestService.php`
- `app/Services/Kb/Export/KbWikiExportService.php`
- `app/Services/Kb/Import/KbWikiImportService.php`
- `app/Services/Kb/Versioning/DocumentVersionService.php`
- `app/Services/TabularReview/TabularReviewExtractor.php`
- `app/Services/TabularReview/VisionColumnResolver.php`
- `app/Support/TabularReview/AgentKind.php`
- `config/actions.php`
- `config/auth.php`
- `config/iam-shadow.php`
- `config/mcp-pack.php`
- `config/routines.php`
- `database/migrations/2026_08_29_000001_create_routines_tables.php`
- `database/migrations/2026_09_27_000001_add_two_factor_columns_to_users_table.php`
- `database/migrations/2026_10_02_000014_create_kb_wiki_export_requests_table.php`
- `database/migrations/2026_10_02_000015_create_kb_wiki_import_candidates_table.php`
- `docs-site/agentic-knowledge-reports.mdx`
- `docs-site/architecture/governed-actions.mdx`
- `docs-site/auto-wiki.mdx`
- `docs-site/introduction.mdx`
- `docs-site/portable-wiki-export.mdx`
- `docs/ENTERPRISE-COMPLETENESS-ROADMAP.md`
- `docs/adr/0032-v838-portable-wiki-export-and-candidate-only-import.md`
- `docs/adr/0033-v839-auto-wiki-maintenance-as-a-delegated-routine.md`
- `docs/adr/0034-v840-vision-column-in-tabular-review.md`
- `docs/architecture/baseline.md`
- `docs/architecture/source-map.md`
- `docs/architecture/tenant-map.md`
- `docs/benchmarks/corpus-spec.md`
- `docs/benchmarks/gap-matrix.md`
- `docs/compatibility.json`
- `docs/handoff/v836-document-intelligence-cycle-kickoff.md`
- `docs/plans/askmydocs-enterprise-roadmap.md`
- `docs/release/dependency-matrix.json`
- `docs/tasks/E00.01.md`
- `docs/tasks/E00.02.md`
- `docs/tasks/E00.03.md`
- `docs/tasks/E01.01.md`
- `docs/tasks/E01.02.md`
- `docs/tasks/E01.03.md`
- `docs/tasks/E02.01.md`
- `docs/tasks/E02.02.md`
- `docs/tasks/W00.01.md`
- `docs/v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`
- `docs/v4-platform/STATUS-2026-09-25-v838-w4-closure.md`
- `docs/v4-platform/STATUS-2026-09-26-v839-w5-closure.md`
- `docs/v4-platform/STATUS-2026-09-26-v840-w6-closure.md`
- `frontend/src/features/admin/tabular-reviews/admin-tabular-reviews.api.ts`
- `frontend/src/security/actionCanonicalizer.test.ts`
- `frontend/src/security/actionCanonicalizer.ts`
- `tests/Feature/Actions/ActionApprovalServiceTest.php`
- `tests/Feature/Agent/AgentServerToolRunnerMcpBudgetTest.php`
- `tests/Feature/Api/Admin/KbWikiExportControllerTest.php`
- `tests/Feature/Api/Admin/KbWikiImportControllerTest.php`
- `tests/Feature/Api/Admin/KbWikiRoutineControllerTest.php`
- `tests/Feature/Api/Auth/TwoFactorStubTest.php`
- `tests/Feature/Api/Auth/TwoFactorTest.php`
- `tests/Feature/Console/KbExportWikiCommandTest.php`
- `tests/Feature/Console/KbImportWikiCommandTest.php`
- `tests/Feature/Console/KbWikiRoutineCommandTest.php`
- `tests/Feature/Console/PruneWikiExportsCommandTest.php`
- `tests/Feature/Jobs/ExecuteKbWikiExportJobTest.php`
- `tests/Feature/Kb/Export/KbWikiExportRequestServiceTest.php`
- `tests/Feature/Kb/Export/KbWikiExportServiceTest.php`
- `tests/Feature/Kb/Import/KbWikiImportServiceTest.php`
- `tests/Feature/Mcp/KbCreateExportToolTest.php`
- `tests/Feature/Mcp/KbGetExportToolTest.php`
- `tests/Feature/Mcp/KbImportWikiToolTest.php`
- `tests/Feature/Mcp/KbProposeTextCorrectionToolTest.php`
- `tests/Feature/Mcp/KbWikiRoutineStatusToolTest.php`
- `tests/Feature/Mcp/McpPiiToolsEndToEndTest.php`
- `tests/Feature/Mcp/McpPrincipalBindingTest.php`
- `tests/Feature/Mcp/McpWriteToolScopeTest.php`
- `tests/Feature/Mcp/RunReportToolTest.php`
- `tests/Feature/Routines/RoutinesWebhookConfigOnTest.php`
- `tests/Feature/Routines/RoutinesWebhookConfigTest.php`
- `tests/Feature/Routines/WikiMaintenanceRoutineGateTest.php`
- `tests/Feature/Routines/WikiMaintenanceRoutineTargetTest.php`
- `tests/Feature/Routines/WikiRoutineServiceTest.php`
- `tests/Feature/Scheduling/TierOneSchedulerEnvOverrideTest.php`
- `tests/Feature/TabularReview/TabularReviewControllerTest.php`
- `tests/Feature/TabularReview/TabularReviewExtractorTest.php`
- `tests/Feature/TabularReview/VisionColumnResolverTest.php`
- `tests/Feature/V7/McpPackArtifactsDefaultOffTest.php`
- `tests/Feature/V7/McpPackArtifactsRouteTest.php`
- `tests/Unit/Actions/ActionCanonicalizerTest.php`
- `tests/Unit/Actions/ActionIntentTest.php`
- `tests/Unit/Authorization/IamShadowEvaluatorTest.php`
- `tests/Unit/Support/TabularReview/AgentKindTest.php`
- `tests/database/migrations/2026_08_29_000001_create_routines_tables.php`
- `tests/database/migrations/2026_09_27_000001_add_two_factor_columns_to_users_table.php`
- `tests/database/migrations/2026_10_02_000014_create_kb_wiki_export_requests_table.php`
- `tests/database/migrations/2026_10_02_000015_create_kb_wiki_import_candidates_table.php`

## File finali diversi da develop

Questo elenco è completo: gli altri file applicativi coincidono con develop. Non sono state escluse funzioni di develop per ridurre il numero di commit.

- `.env.example`
- `.github/workflows/tests.yml`
- `.superpowers/sdd/askmydocs-enterprise-roadmap/progress.md`
- `CLAUDE.md`
- `README.md`
- `app/Actions/ActionApprovalException.php`
- `app/Actions/ActionApprovalReceipt.php`
- `app/Actions/ActionApprovalService.php`
- `app/Actions/ActionCanonicalizer.php`
- `app/Actions/ActionIntent.php`
- `app/Actions/ActionRegistry.php`
- `app/Actions/PendingActionApproval.php`
- `app/Agent/Tools/AgentServerToolRunner.php`
- `app/Agent/Tools/AgentToolRegistry.php`
- `app/Authorization/IamPdp.php`
- `app/Authorization/IamShadowEvaluator.php`
- `app/Authorization/IamShadowReport.php`
- `app/Authorization/SpatieIamPdp.php`
- `app/Console/Commands/KbExportWikiCommand.php`
- `app/Console/Commands/KbImportWikiCommand.php`
- `app/Console/Commands/KbWikiRoutineCommand.php`
- `app/Console/Commands/PruneWikiExportsCommand.php`
- `app/Exceptions/KbWikiImportRateLimitedException.php`
- `app/Http/Controllers/Api/Admin/KbWikiExportController.php`
- `app/Http/Controllers/Api/Admin/KbWikiImportController.php`
- `app/Http/Controllers/Api/Admin/KbWikiRoutineController.php`
- `app/Http/Controllers/Api/Admin/MaintenanceCommandController.php`
- `app/Http/Controllers/Api/Auth/TwoFactorController.php`
- `app/Http/Controllers/Api/Widget/WidgetSessionController.php`
- `app/Http/Middleware/EnforceMcpScope.php`
- `app/Http/Requests/Admin/Kb/StoreKbWikiExportRequest.php`
- `app/Http/Requests/Admin/Kb/StoreKbWikiImportRequest.php`
- `app/Http/Requests/Auth/TwoFactorRequest.php`
- `app/Jobs/ExecuteKbWikiExportJob.php`
- `app/Mcp/Client/McpHandshakeService.php`
- `app/Mcp/Client/McpToolInvocation.php`
- `app/Mcp/Client/McpToolInvocationFailed.php`
- `app/Mcp/Client/ToolInvoker.php`
- `app/Mcp/Servers/KnowledgeBaseServer.php`
- `app/Mcp/Tools/KbCreateExportTool.php`
- `app/Mcp/Tools/KbGetExportTool.php`
- `app/Mcp/Tools/KbImportWikiTool.php`
- `app/Mcp/Tools/KbWikiRoutineStatusTool.php`
- `app/Models/KbWikiExportRequest.php`
- `app/Models/KbWikiImportCandidate.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Routines/WikiMaintenanceRoutineGate.php`
- `app/Routines/WikiMaintenanceRoutineTarget.php`
- `app/Routines/WikiRoutineService.php`
- `app/Scheduling/TierOneSchedulerRegistrar.php`
- `app/Services/Auth/TwoFactorService.php`
- `app/Services/Kb/Export/KbWikiExportRequestService.php`
- `app/Services/Kb/Export/KbWikiExportService.php`
- `app/Services/Kb/Import/KbWikiImportService.php`
- `app/Services/Kb/Versioning/DocumentVersionService.php`
- `app/Services/TabularReview/TabularReviewExtractor.php`
- `app/Services/TabularReview/VisionColumnResolver.php`
- `app/Support/TabularReview/AgentKind.php`
- `bootstrap/app.php`
- `bootstrap/providers.php`
- `composer.json`
- `composer.lock`
- `config/actions.php`
- `config/askmydocs.php`
- `config/auth.php`
- `config/iam-shadow.php`
- `config/kb.php`
- `config/mcp-pack.php`
- `config/routines.php`
- `database/migrations/2026_08_29_000001_create_routines_tables.php`
- `database/migrations/2026_09_27_000001_add_two_factor_columns_to_users_table.php`
- `database/migrations/2026_10_02_000014_create_kb_wiki_export_requests_table.php`
- `database/migrations/2026_10_02_000015_create_kb_wiki_import_candidates_table.php`
- `docs-site/agentic-knowledge-reports.mdx`
- `docs-site/architecture/governed-actions.mdx`
- `docs-site/auto-wiki.mdx`
- `docs-site/docs.json`
- `docs-site/introduction.mdx`
- `docs-site/mcp-server.mdx`
- `docs-site/portable-wiki-export.mdx`
- `docs/ENTERPRISE-COMPLETENESS-ROADMAP.md`
- `docs/adr/0032-v838-portable-wiki-export-and-candidate-only-import.md`
- `docs/adr/0033-v839-auto-wiki-maintenance-as-a-delegated-routine.md`
- `docs/adr/0034-v840-vision-column-in-tabular-review.md`
- `docs/architecture/baseline.md`
- `docs/architecture/source-map.md`
- `docs/architecture/tenant-map.md`
- `docs/benchmarks/corpus-spec.md`
- `docs/benchmarks/gap-matrix.md`
- `docs/compatibility.json`
- `docs/handoff/v836-document-intelligence-cycle-kickoff.md`
- `docs/plans/askmydocs-enterprise-roadmap.md`
- `docs/release/dependency-matrix.json`
- `docs/tasks/E00.01.md`
- `docs/tasks/E00.02.md`
- `docs/tasks/E00.03.md`
- `docs/tasks/E01.01.md`
- `docs/tasks/E01.02.md`
- `docs/tasks/E01.03.md`
- `docs/tasks/E02.01.md`
- `docs/tasks/E02.02.md`
- `docs/tasks/W00.01.md`
- `docs/v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`
- `docs/v4-platform/STATUS-2026-09-25-v838-w4-closure.md`
- `docs/v4-platform/STATUS-2026-09-26-v839-w5-closure.md`
- `docs/v4-platform/STATUS-2026-09-26-v840-w6-closure.md`
- `frontend/e2e/admin-tabular-reviews.spec.ts`
- `frontend/src/features/admin/tabular-reviews/TabularReviewsList.tsx`
- `frontend/src/features/admin/tabular-reviews/admin-tabular-reviews.api.ts`
- `frontend/src/security/actionCanonicalizer.test.ts`
- `frontend/src/security/actionCanonicalizer.ts`
- `frontend/src/styles/buttons.css`
- `package-lock.json`
- `package.json`
- `routes/api.php`
- `tests/Architecture/RouteExposureTest.php`
- `tests/Architecture/TenantIdMandatoryTest.php`
- `tests/Architecture/TenantReadScopeTest.php`
- `tests/Feature/Actions/ActionApprovalServiceTest.php`
- `tests/Feature/Agent/AgentServerToolRunnerMcpBudgetTest.php`
- `tests/Feature/Api/Admin/KbWikiExportControllerTest.php`
- `tests/Feature/Api/Admin/KbWikiImportControllerTest.php`
- `tests/Feature/Api/Admin/KbWikiRoutineControllerTest.php`
- `tests/Feature/Api/Admin/McpServersAdminControllerTest.php`
- `tests/Feature/Api/Auth/TwoFactorStubTest.php`
- `tests/Feature/Api/Auth/TwoFactorTest.php`
- `tests/Feature/Console/KbExportWikiCommandTest.php`
- `tests/Feature/Console/KbImportWikiCommandTest.php`
- `tests/Feature/Console/KbWikiRoutineCommandTest.php`
- `tests/Feature/Console/PruneWikiExportsCommandTest.php`
- `tests/Feature/Jobs/ExecuteKbWikiExportJobTest.php`
- `tests/Feature/Kb/Export/KbWikiExportRequestServiceTest.php`
- `tests/Feature/Kb/Export/KbWikiExportServiceTest.php`
- `tests/Feature/Kb/Import/KbWikiImportServiceTest.php`
- `tests/Feature/Mcp/KbCreateExportToolTest.php`
- `tests/Feature/Mcp/KbGetExportToolTest.php`
- `tests/Feature/Mcp/KbImportWikiToolTest.php`
- `tests/Feature/Mcp/KbProposeTextCorrectionToolTest.php`
- `tests/Feature/Mcp/KbWikiRoutineStatusToolTest.php`
- `tests/Feature/Mcp/McpPiiToolsEndToEndTest.php`
- `tests/Feature/Mcp/McpPrincipalBindingTest.php`
- `tests/Feature/Mcp/McpWriteToolScopeTest.php`
- `tests/Feature/Mcp/RunReportToolTest.php`
- `tests/Feature/Routines/RoutinesWebhookConfigOnTest.php`
- `tests/Feature/Routines/RoutinesWebhookConfigTest.php`
- `tests/Feature/Routines/WikiMaintenanceRoutineGateTest.php`
- `tests/Feature/Routines/WikiMaintenanceRoutineTargetTest.php`
- `tests/Feature/Routines/WikiRoutineServiceTest.php`
- `tests/Feature/Scheduling/TierOneSchedulerEnvOverrideTest.php`
- `tests/Feature/Security/AdminAuthorizationMatrixTest.php`
- `tests/Feature/TabularReview/TabularReviewControllerTest.php`
- `tests/Feature/TabularReview/TabularReviewExtractorTest.php`
- `tests/Feature/TabularReview/VisionColumnResolverTest.php`
- `tests/Feature/V7/McpPackArtifactsDefaultOffTest.php`
- `tests/Feature/V7/McpPackArtifactsRouteTest.php`
- `tests/Feature/V7/PackageRoundTripIntegrationTest.php`
- `tests/TestCase.php`
- `tests/Unit/Actions/ActionCanonicalizerTest.php`
- `tests/Unit/Actions/ActionIntentTest.php`
- `tests/Unit/Authorization/IamShadowEvaluatorTest.php`
- `tests/Unit/Support/TabularReview/AgentKindTest.php`
- `tests/database/migrations/2026_08_29_000001_create_routines_tables.php`
- `tests/database/migrations/2026_09_27_000001_add_two_factor_columns_to_users_table.php`
- `tests/database/migrations/2026_10_02_000014_create_kb_wiki_export_requests_table.php`
- `tests/database/migrations/2026_10_02_000015_create_kb_wiki_import_candidates_table.php`

## Due aggiustamenti emersi nella verifica

- La coppia Dialog 1.2.0 di main e Select 2.3.7 di develop installava versioni diverse dei focus scope e dismissable layer Radix. Il test già esistente del menu dentro un dialogo falliva su Escape. Dialog è quindi fissato a **1.1.23**, coerente con Select e Popover di develop; il test di regressione passa senza modificare i componenti o indebolire le asserzioni. È una scelta intenzionale di compatibilità rispetto all’aggiornamento di main.
- È rimossa una sola riga vuota a fine `frontend/src/styles/buttons.css`, necessaria per portare `git diff --check` a zero. Oltre alle riconciliazioni con main, questa è l’unica differenza applicativa rispetto a develop.
