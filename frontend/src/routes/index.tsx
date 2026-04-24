import {
    createRootRoute,
    createRoute,
    createRouter,
    Outlet,
    redirect,
    useNavigate,
    useSearch,
} from '@tanstack/react-router';
import { z } from 'zod';
import { AppShell } from '../components/shell/AppShell';
import { ChatView } from '../features/chat/ChatView';
import { DashboardView } from '../features/admin/dashboard/DashboardView';
import { UsersView } from '../features/admin/users/UsersView';
import { RolesView } from '../features/admin/roles/RolesView';
import { KbView } from '../features/admin/kb/KbView';
import { LogsView } from '../features/admin/logs/LogsView';
import { DashboardPlaceholder } from '../components/sections/DashboardPlaceholder';
import { RequireRole } from './role-guard';
import { KbPlaceholder } from '../components/sections/KbPlaceholder';
import { InsightsPlaceholder } from '../components/sections/InsightsPlaceholder';
import { UsersPlaceholder } from '../components/sections/UsersPlaceholder';
import { MaintenancePlaceholder } from '../components/sections/MaintenancePlaceholder';
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
const chatConversationRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'chat/$conversationId',
    component: ChatView,
});
const dashboardRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'dashboard',
    component: DashboardPlaceholder,
});
const kbRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'kb',
    component: KbPlaceholder,
});
const insightsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'insights',
    component: InsightsPlaceholder,
});
const usersRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'users',
    component: UsersPlaceholder,
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
    component: MaintenancePlaceholder,
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

const adminKbRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'admin/kb',
    component: AdminKbRoute,
});

const routeTree = rootRoute.addChildren([
    indexRoute,
    loginRoute,
    forgotRoute,
    resetRoute,
    appRoute.addChildren([
        appIndexRoute,
        chatRoute,
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
        adminLogsRoute,
    ]),
]);

export const router = createRouter({ routeTree, defaultPreload: 'intent' });

declare module '@tanstack/react-router' {
    interface Register {
        router: typeof router;
    }
}
