import {
    createRootRoute,
    createRoute,
    createRouter,
    Outlet,
    redirect,
    useNavigate,
    useParams,
    useSearch,
} from '@tanstack/react-router';
import { z } from 'zod';
import { AppShell } from '../components/shell/AppShell';
import { ChatView } from '../features/chat/ChatView';
import { AnonymousChatView } from '../features/chat/AnonymousChatView';
import { DashboardView } from '../features/admin/dashboard/DashboardView';
import { UsersView } from '../features/admin/users/UsersView';
import { RolesView } from '../features/admin/roles/RolesView';
import { KbView } from '../features/admin/kb/KbView';
import { KbHealthView } from '../features/admin/kb-health/KbHealthView';
import { TagsList } from '../features/admin/tags/TagsList';
import { SynonymsList } from '../features/admin/synonyms/SynonymsList';
import { KbInsightsView } from '../features/admin/kb-insights/KbInsightsView';
import { AnalysisSettingsView } from '../features/admin/analysis-settings/AnalysisSettingsView';
import { ContentGapsView } from '../features/admin/content-gaps/ContentGapsView';
import { WikiHealthView } from '../features/admin/wiki-health/WikiHealthView';
import { TimeMachineView } from '../features/admin/time-machine/TimeMachineView';
import { LogsView } from '../features/admin/logs/LogsView';
import { MaintenanceView } from '../features/admin/maintenance/MaintenanceView';
import { InsightsView } from '../features/admin/insights/InsightsView';
import { PiiRedactorView } from '../features/admin/pii-redactor/PiiRedactorView';
import { FlowsView } from '../features/admin/flows/FlowsView';
import { EvalHarnessView } from '../features/admin/eval-harness/EvalHarnessView';
import { ConnectorsView } from '../features/admin/connectors/ConnectorsView';
import { ConnectorCallback } from '../features/admin/connectors/ConnectorCallback';
import { AiActComplianceView } from '../features/admin/ai-act-compliance/AiActComplianceView';
import { TabularReviewsList } from '../features/admin/tabular-reviews/TabularReviewsList';
import { McpToolsView } from '../features/admin/mcp-tools/McpToolsView';
import { McpTokensView } from '../features/admin/mcp-tokens/McpTokensView';
import { CollectionsView } from '../features/admin/collections/CollectionsView';
import { ComplianceReportsView } from '../features/admin/compliance/ComplianceReportsView';
import { WorkflowsList } from '../features/admin/workflows/WorkflowsList';
import { NotificationPanel } from '../features/notifications/NotificationPanel';
import { NotificationPreferencesGrid } from '../features/notifications/NotificationPreferencesGrid';
import { AdminNotificationDefaultsGrid } from '../features/notifications/AdminNotificationDefaultsGrid';
import { WidgetAdminView } from '../features/admin/widget/WidgetAdminView';
import { AdminShell } from '../features/admin/shell/AdminShell';
import { RequireRole } from './role-guard';
import { LoginPage } from '../features/auth/LoginPage';
import { ForgotPasswordPage } from '../features/auth/ForgotPasswordPage';
import { ResetPasswordPage } from '../features/auth/ResetPasswordPage';
import { RedirectIfAuth, RequireAuth, useAuthBootstrap } from './guards';
import { useAuthStore } from '../lib/auth-store';

function RootLayout() {
    useAuthBootstrap();
    return <Outlet />;
}

const rootRoute = createRootRoute({ component: RootLayout });

const indexRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/',
    beforeLoad: () => {
        const user = useAuthStore.getState().user;
        throw redirect({ to: user ? '/app' : '/login' });
    },
});

function LoginRoute() {
    const navigate = useNavigate();
    return (
        <RedirectIfAuth>
            <LoginPage
                onSuccess={() => navigate({ to: '/app' })}
                onNavigateForgot={() => navigate({ to: '/forgot-password' })}
            />
        </RedirectIfAuth>
    );
}

const loginRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/login',
    component: LoginRoute,
});

function ForgotRoute() {
    const navigate = useNavigate();
    return (
        <RedirectIfAuth>
            <ForgotPasswordPage onBackToLogin={() => navigate({ to: '/login' })} />
        </RedirectIfAuth>
    );
}

const forgotRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/forgot-password',
    component: ForgotRoute,
});

const resetSearchSchema = z.object({
    token: z.string().default(''),
    email: z.string().default(''),
});

function ResetRoute() {
    const navigate = useNavigate();
    const { token, email } = useSearch({ from: '/reset-password' });
    return (
        <RedirectIfAuth>
            <ResetPasswordPage token={token} email={email} onDone={() => navigate({ to: '/login' })} />
        </RedirectIfAuth>
    );
}

const resetRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/reset-password',
    validateSearch: resetSearchSchema,
    component: ResetRoute,
});

function AppLayout() {
    return (
        <RequireAuth>
            <AppShell />
        </RequireAuth>
    );
}

const appRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/app',
    component: AppLayout,
});

const appIndexRoute = createRoute({
    getParentRoute: () => appRoute,
    path: '/',
    beforeLoad: () => {
        throw redirect({ to: '/app/chat' });
    },
});

// Copilot #11 fix: flat chat routes instead of nesting
// `$conversationId` under a parent that also renders `ChatView`.
// TanStack Router only mounts a child route inside the parent's
// `<Outlet />`, and `ChatView` doesn't (and shouldn't) render one —
// so the nested variant never matched `useParams({strict:false})`
// for `/app/chat/:conversationId`. Two sibling routes under
// `appRoute` let `ChatView` receive the param in both shapes.
const chatRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'chat',
    component: ChatView,
});
// v8.8.3 — anonymous (non-persisted) chat. Declared as a STATIC sibling
// BEFORE `chat/$conversationId` so TanStack's static-over-dynamic matching
// resolves `/app/chat/anonymous` to the dedicated view rather than treating
// "anonymous" as a conversation id. Self-contained (no streaming/conversation
// machinery) so the feature toggle never destabilises the normal chat path.
const chatAnonymousRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'chat/anonymous',
    component: AnonymousChatView,
});
const chatConversationRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'chat/$conversationId',
    component: ChatView,
});
// These five paths shipped as `Coming in Phase …` placeholders in early
// phases. The real views now live under `/app/admin/*` (DashboardView,
// KbView, InsightsView, UsersView, MaintenanceView) and the primary sidebar
// links there directly (AppShell SECTION_ROUTES). The old paths redirect to
// the real targets so any stale bookmark / deep link lands on the working
// view instead of a dead-end stub.
const dashboardRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'dashboard',
    beforeLoad: () => {
        throw redirect({ to: '/app/admin' });
    },
});
const kbRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'kb',
    beforeLoad: () => {
        throw redirect({ to: '/app/admin/kb' });
    },
});
const insightsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'insights',
    beforeLoad: () => {
        throw redirect({ to: '/app/admin/insights' });
    },
});
const usersRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'users',
    beforeLoad: () => {
        throw redirect({ to: '/app/admin/users' });
    },
});
// Phase H1 — admin Log Viewer route. Same flat RBAC pattern as
// AdminKbRoute: the RequireRole gate lives inside the component so
// a viewer hitting /app/admin/logs directly sees <AdminForbidden />.
function AdminLogsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <LogsView />
        </RequireRole>
    );
}

const logsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'logs',
    component: AdminLogsRoute,
});

const adminLogsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/logs',
    component: AdminLogsRoute,
});
const maintenanceRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'maintenance',
    beforeLoad: () => {
        throw redirect({ to: '/app/admin/maintenance' });
    },
});

// Phase H2 — admin Maintenance panel route. Same flat RBAC pattern as
// AdminLogsRoute / AdminKbRoute: RequireRole gate lives inside the
// component so a viewer hitting /app/admin/maintenance sees
// <AdminForbidden /> instead of a crash.
function AdminMaintenanceRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <MaintenanceView />
        </RequireRole>
    );
}

const adminMaintenanceRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/maintenance',
    component: AdminMaintenanceRoute,
});

// Flat admin route — same shape as `chatRoute` to keep PR #20's
// Copilot #11 rule: no nesting inside a component-less parent. The
// RBAC gate lives inside the component so a viewer hitting the URL
// sees <AdminForbidden /> instead of a crash.
function AdminRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <DashboardView />
        </RequireRole>
    );
}

const adminRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin',
    component: AdminRoute,
});

// PR7 / Phase F2 — flat admin children. Same shape as `chatRoute`
// vs `chatConversationRoute` in PR20: component-less parents do
// not render an <Outlet /> for children, so we declare siblings
// under `appRoute` with deeper paths ("admin/users", "admin/roles")
// and guard the actual content with RequireRole inside the
// component. The rail in AdminShell navigates to the flat path;
// the RBAC gate matches `AdminRoute` — a viewer hitting the URL
// sees <AdminForbidden /> instead of a crash.
function AdminUsersRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <UsersView />
        </RequireRole>
    );
}

function AdminRolesRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <RolesView />
        </RequireRole>
    );
}

// PR8 / Phase G1 — Admin KB explorer. Same flat pattern as
// AdminUsersRoute: RBAC gate inside the component, flat path
// under appRoute.
function AdminKbRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <KbView />
        </RequireRole>
    );
}

const adminUsersRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/users',
    component: AdminUsersRoute,
});

const adminRolesRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/roles',
    component: AdminRolesRoute,
});

// `doc` + `tab` deep-link into a specific KB document detail (e.g. when a
// chat citation chip is clicked). Declared here so TanStack preserves the
// query string on navigation; KbView reads it via window.location.search
// (parseInitialUrl) to open the document on mount.
const adminKbSearchSchema = z.object({
    doc: z.coerce.number().int().positive().optional(),
    tab: z.enum(['preview', 'source', 'meta', 'history', 'graph']).optional(),
});

const adminKbRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb',
    validateSearch: adminKbSearchSchema,
    component: AdminKbRoute,
});

function AdminKbHealthRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <KbHealthView />
        </RequireRole>
    );
}

const adminKbHealthRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb/health',
    component: AdminKbHealthRoute,
});

// T2.10 — Admin Tags. Same flat-RBAC + RequireRole wrapping pattern as
// adminKbRoute / adminInsightsRoute so direct hits to /app/admin/kb/tags
// resolve to either the view or <AdminForbidden /> on viewer/guest.
function AdminTagsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <TagsList />
        </RequireRole>
    );
}

const adminTagsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb/tags',
    component: AdminTagsRoute,
});

// v8.7/W1 — Admin Synonyms. Wrapped in AdminShell (rail entry +
// section highlight) with the same flat-RBAC RequireRole pattern.
function AdminSynonymsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="synonyms">
                <SynonymsList />
            </AdminShell>
        </RequireRole>
    );
}

const adminSynonymsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb/synonyms',
    component: AdminSynonymsRoute,
});

// v8.7/W3–W4 — Doc Insights (AI document-change analyses).
function AdminKbInsightsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="kb-insights">
                <KbInsightsView />
            </AdminShell>
        </RequireRole>
    );
}

const adminKbInsightsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb/insights',
    component: AdminKbInsightsRoute,
});

// v8.8/W3 — per-(tenant, project) deep-analysis gate override.
function AdminAnalysisSettingsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="analysis-settings">
                <AnalysisSettingsView />
            </AdminShell>
        </RequireRole>
    );
}

const adminAnalysisSettingsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb/analysis-settings',
    component: AdminAnalysisSettingsRoute,
});

// v8.8/W4 — content-gap analytics (unanswered questions).
function AdminContentGapsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="content-gaps">
                <ContentGapsView />
            </AdminShell>
        </RequireRole>
    );
}

const adminContentGapsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb/content-gaps',
    component: AdminContentGapsRoute,
});

// v8.11/P10 — Wiki Health (Auto-Wiki lint report + safe auto-fix).
function AdminWikiHealthRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="wiki-health">
                <WikiHealthView />
            </AdminShell>
        </RequireRole>
    );
}

const adminWikiHealthRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb/wiki-health',
    component: AdminWikiHealthRoute,
});

// v8.7/W5 — Cloud Time Machine (per-document version timeline + diff + restore).
function AdminKbTimeMachineRoute() {
    const params = useParams({ strict: false }) as { docId?: string };
    const docId = Number(params.docId);
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="time-machine">
                {Number.isFinite(docId) && docId > 0 ? (
                    <TimeMachineView docId={docId} />
                ) : (
                    <p data-testid="kb-time-machine-invalid" style={{ padding: 24, color: 'var(--err)' }}>
                        Invalid document id.
                    </p>
                )}
            </AdminShell>
        </RequireRole>
    );
}

const adminKbTimeMachineRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb/time-machine/$docId',
    component: AdminKbTimeMachineRoute,
});

// PR14 / Phase I — Admin Insights. Same flat RBAC pattern — guard
// inside the component so direct /app/admin/insights hits always
// resolve to either the view or <AdminForbidden /> on viewer.
function AdminInsightsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <InsightsView />
        </RequireRole>
    );
}

const adminInsightsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/insights',
    component: AdminInsightsRoute,
});

// v4.2/W4 sub-PR 5 — PII Redactor admin SPA mount.
// v4.4/W2 — switched from iframe to cross-mount of the package's
// React tree (see PiiRedactorView.tsx + ADR 0005 for the rationale).
// Same flat-RBAC pattern as AdminInsightsRoute / AdminKbRoute: the
// RequireRole gate lives inside the component so a viewer hitting
// /app/admin/pii-redactor directly sees <AdminForbidden /> instead of
// a crash. The Spatie role allowlist matches the BE Gate
// `viewPiiRedactorAdmin` (super-admin / dpo / admin); the package
// API routes (/admin/pii-redactor/api/*) are enforced separately by
// the BE `can:viewPiiRedactorAdmin` middleware so an unprivileged
// user who reaches an endpoint still gets a 403 from Laravel.
function AdminPiiRedactorRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin', 'dpo']}>
            <PiiRedactorView />
        </RequireRole>
    );
}

const adminPiiRedactorRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/pii-redactor',
    component: AdminPiiRedactorRoute,
});

// v4.2/W4 sub-PR 6 — Flow Admin SPA mount. Same flat-RBAC pattern as
// AdminPiiRedactorRoute / AdminInsightsRoute: the RequireRole gate
// lives inside the component so a viewer hitting /app/admin/flows
// directly sees <AdminForbidden /> instead of a crash. The Spatie
// role allowlist matches the BE Gate `viewFlowAdmin` (super-admin /
// admin / dpo); the iframe URL (/admin/flows) is enforced separately
// by the BE `can:viewFlowAdmin` middleware AND the
// `flow-admin.enabled` middleware so an unprivileged user who somehow
// reaches the URL gets a 403 (or 404 when env=false) from Laravel.
function AdminFlowsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin', 'dpo']}>
            <FlowsView />
        </RequireRole>
    );
}

const adminFlowsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/flows',
    component: AdminFlowsRoute,
});

// v4.2/W4 sub-PR 7 — Eval Harness UI dashboard mount.
// v4.4/W3 — switched from iframe to cross-mount of the package's
// React tree (see EvalHarnessView.tsx + ADR 0005 for the rationale).
// Same flat-RBAC pattern as AdminFlowsRoute / AdminPiiRedactorRoute:
// the RequireRole gate lives inside the component so a viewer hitting
// /app/admin/eval-harness directly sees <AdminForbidden /> instead of
// a crash. The Spatie role allowlist matches the BE Gate
// `eval-harness.viewer` (super-admin / admin / dpo / editor); the
// package API routes (/admin/eval-harness/api/*) are enforced
// separately by the BE `can:eval-harness.viewer` middleware AND the
// `eval-harness-ui.non-prod` middleware (404 in production) AND the
// package controller's own `eval-harness-ui.enabled` check (404 when
// env=false), so an unprivileged user who somehow reaches the URL
// gets a 403 / 404 from Laravel.
//
// The route uses a splat (`$`) because the cross-mounted SPA owns 8
// internal sub-routes (Dashboard / Reports / ReportDetail / Compare /
// Trend / Adversarial / AdversarialDetail / LiveBatches) via its own
// `BrowserRouter basename="/app/admin/eval-harness"`. Without the
// splat, a direct hit on `/app/admin/eval-harness/reports` would
// cascade to the TanStack 404 handler before the BrowserRouter ever
// got a chance to mount.
function AdminEvalHarnessRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin', 'dpo', 'editor']}>
            <EvalHarnessView />
        </RequireRole>
    );
}

// Bare-path route: handles the sidebar entry click + direct hits on
// the dashboard root (/app/admin/eval-harness).
const adminEvalHarnessRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/eval-harness',
    component: AdminEvalHarnessRoute,
});

function AdminAiActComplianceRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin', 'dpo']}>
            <AiActComplianceView />
        </RequireRole>
    );
}

const adminAiActComplianceRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/ai-act-compliance',
    component: AdminAiActComplianceRoute,
});

const adminAiActComplianceSplatRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/ai-act-compliance/$',
    component: AdminAiActComplianceRoute,
});

// v4.5/W3 — Connector admin routes. Two routes:
//   /app/admin/connectors                       → list view
//   /app/admin/connectors/$key/callback         → OAuth callback handler
//
// Same flat-RBAC pattern: the BE Gate `manageConnectors` is the
// authoritative defence (super-admin only); the FE <RequireRole>
// guard short-circuits to <AdminForbidden /> for unprivileged roles so
// a viewer hitting /app/admin/connectors directly never sees a 403
// fetch storm.
function AdminConnectorsRoute() {
    return (
        <RequireRole roles={['super-admin']}>
            <ConnectorsView />
        </RequireRole>
    );
}

function AdminConnectorCallbackRoute() {
    const params = useParams({ strict: false }) as { key?: string };
    return (
        <RequireRole roles={['super-admin']}>
            <ConnectorCallback connectorKey={params.key ?? ''} />
        </RequireRole>
    );
}

const adminConnectorsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/connectors',
    component: AdminConnectorsRoute,
});

// v4.7/W3 — Tabular Reviews + Workflows admin SPA routes.
// `viewTabularReviews` / `viewWorkflows` BE Gates admit the `viewer`
// role for READ-ONLY access (the BE controllers' denyMutationForViewer()
// guards individual write actions). The FE rail entry must mirror that
// admission set so a viewer hitting the URL sees the list page rather
// than <AdminForbidden />; the components themselves treat write attempts
// as 403 from the BE response.
function AdminTabularReviewsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin', 'viewer']}>
            <TabularReviewsList />
        </RequireRole>
    );
}

function AdminWorkflowsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin', 'viewer']}>
            <WorkflowsList />
        </RequireRole>
    );
}

const adminTabularReviewsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/tabular-reviews',
    component: AdminTabularReviewsRoute,
});

const adminWorkflowsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/workflows',
    component: AdminWorkflowsRoute,
});

// v5.0/W2 — MCP tools admin route. The BE Gate `manageMcpTools`
// (super-admin only) is the authoritative defence; the FE <RequireRole>
// guard short-circuits to <AdminForbidden /> for unprivileged roles so
// a viewer hitting /app/admin/mcp-tools directly never sees a 403
// fetch storm.
function AdminMcpToolsRoute() {
    return (
        <RequireRole roles={['super-admin']}>
            <McpToolsView />
        </RequireRole>
    );
}

const adminMcpToolsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/mcp-tools',
    component: AdminMcpToolsRoute,
});

function AdminMcpTokensRoute() {
    return (
        <RequireRole roles={['super-admin']}>
            <AdminShell section="mcp-tokens">
                <McpTokensView />
            </AdminShell>
        </RequireRole>
    );
}

const adminMcpTokensRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/mcp/tokens',
    component: AdminMcpTokensRoute,
});

function AdminCollectionsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="collections">
                <CollectionsView />
            </AdminShell>
        </RequireRole>
    );
}

const adminCollectionsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/collections',
    component: AdminCollectionsRoute,
});

function AdminWidgetRoute() {
    // #31 — admin + super-admin (NON solo super-admin): il gate BE
    // `viewWidgetSessions` ammette `admin` per la tab Sessions. La gestione
    // chiavi (tab Keys/Integration) resta super-admin, gated DENTRO WidgetAdminView.
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="widget">
                <WidgetAdminView />
            </AdminShell>
        </RequireRole>
    );
}

const adminWidgetRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/widget',
    component: AdminWidgetRoute,
});

function AdminComplianceReportsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <ComplianceReportsView />
        </RequireRole>
    );
}

const adminComplianceReportsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/compliance/reports',
    component: AdminComplianceReportsRoute,
});

// v8.0/W1.4 — full notification panel route. Accessible to any
// authenticated user (notifications are per-user, not admin-only);
// no RequireRole wrapper. The Topbar's NotificationBell links here
// via the "See all" link in its dropdown.
//
// Copilot iter-3 #4 — wrap in AdminShell so the panel inherits the
// secondary admin rail every other /app/admin/* page uses. The
// wrap lives in the route (not in NotificationPanel itself)
// because AdminShell uses `useNavigate` from TanStack Router and
// would require a Router context in Vitest unit tests otherwise.
// Copilot iter-6 #2 — pass the dedicated `notifications` section
// so no neighbouring rail entry highlights as active while the user
// is on this page (the rail has no notifications entry by design;
// users reach the panel from the bell's See-all link).
function AdminNotificationsRoute() {
    return (
        <AdminShell section="notifications">
            <NotificationPanel />
        </AdminShell>
    );
}

const adminNotificationsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/notifications',
    component: AdminNotificationsRoute,
});

// v8.0/W2.2 — per-user notification preferences grid. Same auth
// surface as the panel (any authenticated user). Wrapped in
// AdminShell with the `notifications` section so the rail
// highlights stay consistent with the panel page.
function AdminNotificationPreferencesRoute() {
    return (
        <AdminShell section="notifications">
            <NotificationPreferencesGrid />
        </AdminShell>
    );
}

const adminNotificationPreferencesRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/notifications/preferences',
    component: AdminNotificationPreferencesRoute,
});

// v8.0/W2.3 — admin tenant-defaults grid. Read open to admin +
// super-admin (route ACL on the BE); PUT rejected with 403 for
// non-super-admin. Wrapped in `RequireRole` so a viewer hitting
// `/app/admin/notifications/defaults` directly sees the standard
// `<AdminForbidden />` guard instead of bouncing off the BE 403
// (Copilot iter-4 — mirrors the AdminLogsRoute / AdminMaintenanceRoute
// pattern in this file).
function AdminNotificationDefaultsRoute() {
    return (
        <RequireRole roles={['admin', 'super-admin']}>
            <AdminShell section="notifications">
                <AdminNotificationDefaultsGrid />
            </AdminShell>
        </RequireRole>
    );
}

const adminNotificationDefaultsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/notifications/defaults',
    component: AdminNotificationDefaultsRoute,
});

const adminConnectorCallbackRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/connectors/$key/callback',
    component: AdminConnectorCallbackRoute,
});

// Splat sibling route: handles direct hits on every BrowserRouter
// sub-path (/app/admin/eval-harness/reports, /reports/<id>, /compare,
// /trend, /adversarial, /adversarial/<name>, /live-batches). Without
// this, a browser refresh on a sub-page would 404 at the TanStack
// layer before the cross-mounted BrowserRouter ever ran. Both routes
// render the same `<EvalHarnessView />` so the component lifecycle
// stays identical regardless of the entry URL.
const adminEvalHarnessSplatRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/eval-harness/$',
    component: AdminEvalHarnessRoute,
});

const routeTree = rootRoute.addChildren([
    indexRoute,
    loginRoute,
    forgotRoute,
    resetRoute,
    appRoute.addChildren([
        appIndexRoute,
        chatRoute,
        chatAnonymousRoute,
        chatConversationRoute,
        dashboardRoute,
        kbRoute,
        insightsRoute,
        usersRoute,
        logsRoute,
        maintenanceRoute,
        adminRoute,
        adminUsersRoute,
        adminRolesRoute,
        adminKbRoute,
        adminKbHealthRoute,
        adminTagsRoute,
        adminSynonymsRoute,
        adminKbInsightsRoute,
        adminAnalysisSettingsRoute,
        adminContentGapsRoute,
        adminWikiHealthRoute,
        adminKbTimeMachineRoute,
        adminLogsRoute,
        adminMaintenanceRoute,
        adminInsightsRoute,
        adminPiiRedactorRoute,
        adminFlowsRoute,
        adminEvalHarnessRoute,
        adminEvalHarnessSplatRoute,
        adminAiActComplianceRoute,
        adminAiActComplianceSplatRoute,
        adminConnectorsRoute,
        adminConnectorCallbackRoute,
        adminTabularReviewsRoute,
        adminWorkflowsRoute,
        adminMcpToolsRoute,
        adminMcpTokensRoute,
        adminCollectionsRoute,
        adminWidgetRoute,
        adminComplianceReportsRoute,
        adminNotificationsRoute,
        adminNotificationPreferencesRoute,
        adminNotificationDefaultsRoute,
    ]),
]);

export const router = createRouter({ routeTree, defaultPreload: 'intent' });

declare module '@tanstack/react-router' {
    interface Register {
        router: typeof router;
    }
}
