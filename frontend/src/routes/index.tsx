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
import { DashboardPlaceholder } from '../components/sections/DashboardPlaceholder';
import { KbPlaceholder } from '../components/sections/KbPlaceholder';
import { InsightsPlaceholder } from '../components/sections/InsightsPlaceholder';
import { UsersPlaceholder } from '../components/sections/UsersPlaceholder';
import { LogsPlaceholder } from '../components/sections/LogsPlaceholder';
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

// Laravel's default password-reset notification generates URLs of the
// form `/reset-password/{token}?email=…`. Matching that shape here
// keeps the default ResetPassword notification working without a
// backend override (route name `password.reset` is preserved in
// routes/web.php). The SPA reads `token` from the path param and
// `email` from the query — same contract as the Blade reset page.
const resetSearchSchema = z.object({
    email: z.string().default(''),
});

function ResetRoute() {
    const navigate = useNavigate();
    const { token } = useParams({ from: '/reset-password/$token' });
    const { email } = useSearch({ from: '/reset-password/$token' });
    return (
        <RedirectIfAuth>
            <ResetPasswordPage token={token} email={email} onDone={() => navigate({ to: '/login' })} />
        </RedirectIfAuth>
    );
}

const resetRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/reset-password/$token',
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
const logsRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'logs',
    component: LogsPlaceholder,
});
const maintenanceRoute = createRoute({
    getParentRoute: () => appRoute,
    path: 'maintenance',
    component: MaintenancePlaceholder,
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
    ]),
]);

export const router = createRouter({ routeTree, defaultPreload: 'intent' });

declare module '@tanstack/react-router' {
    interface Register {
        router: typeof router;
    }
}
