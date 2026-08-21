import { useCallback, useEffect, useState } from "react";
import { ApiError, fetchMe, logout as apiLogout } from "./lib/api";
import {
  clearWorkspaceContext,
  clearSession,
  loadSession,
  loadWorkspaceContext,
  saveWorkspaceContext,
  type Session,
} from "./lib/store";
import type { MePayload, Team } from "./lib/types";
import { TenantLogo } from "./components/TenantLogo";
import { ChatScreen } from "./screens/ChatScreen";
import { LoginScreen } from "./screens/LoginScreen";
import { RegisterScreen } from "./screens/RegisterScreen";
import { SearchScreen } from "./screens/SearchScreen";
import { WorkspaceScreen } from "./screens/WorkspaceScreen";
import "./App.css";

type Tab = "chat" | "search";
type AuthView = "login" | "register";

// Mirror the SPA's primary-role pick (highest-privilege wins) for the badge.
const ROLE_RANK = ["super-admin", "admin", "dpo", "editor", "viewer"];
function primaryRole(roles: string[]): string {
  for (const role of ROLE_RANK) {
    if (roles.includes(role)) {
      return role;
    }
  }
  return roles[0] ?? "—";
}

function App() {
  const [session, setSession] = useState<Session | null>(null);
  const [booting, setBooting] = useState(true);
  const [authView, setAuthView] = useState<AuthView>("login");
  const [tab, setTab] = useState<Tab>("chat");
  const [me, setMe] = useState<MePayload | null>(null);
  const [activeTenantId, setActiveTenantId] = useState<string | null>(null);
  const [activeProjectKey, setActiveProjectKey] = useState<string | null>(null);
  const [meError, setMeError] = useState("");
  const [profileLoading, setProfileLoading] = useState(false);
  const [profileAttempt, setProfileAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;
    loadSession().then((stored) => {
      if (cancelled) {
        return;
      }
      setSession(stored);
      setBooting(false);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (!session) {
      setAuthView("login");
    }
  }, [session]);

  const handleLogout = useCallback(async () => {
    if (session) {
      await apiLogout(session.token);
    }
    await clearSession();
    setSession(null);
    setMe(null);
    setActiveTenantId(null);
    setActiveProjectKey(null);
    setTab("chat");
  }, [session]);

  // Load identity (teams / roles / projects) whenever a session exists, and
  // restore a persisted tenant + project pair only when both memberships are
  // still valid. A tenant-only fallback would create an ambiguous session.
  useEffect(() => {
    if (!session) {
      setMe(null);
      setProfileLoading(false);
      return;
    }
    let cancelled = false;
    setProfileLoading(true);
    setMeError("");
    fetchMe(session.token)
      .then(async (payload) => {
        if (cancelled) {
          return;
        }
        setMe(payload);
        const stored = await loadWorkspaceContext();
        if (cancelled) {
          return;
        }
        const validTeam = payload.teams.find(
          (team) => team.tenant_id === stored?.tenantId,
        );
        const validProject = validTeam?.projects.find(
          (project) => project.project_key === stored?.projectKey,
        );
        setActiveTenantId(validTeam && validProject ? validTeam.tenant_id : null);
        setActiveProjectKey(validProject?.project_key ?? null);
        setProfileLoading(false);
      })
      .catch((err) => {
        if (cancelled) {
          return;
        }
        if (err instanceof ApiError && err.status === 401) {
          void handleLogout();
          return;
        }
        setProfileLoading(false);
        setMeError(
          err instanceof ApiError ? err.message : "Could not load your profile.",
        );
      });
    return () => {
      cancelled = true;
    };
  }, [session, handleLogout, profileAttempt]);

  function selectWorkspace(tenantId: string, projectKey: string) {
    setActiveTenantId(tenantId);
    setActiveProjectKey(projectKey);
    setTab("chat");
    void saveWorkspaceContext({ tenantId, projectKey });
  }

  function switchTeam(tenantId: string) {
    setActiveTenantId(tenantId);
    setActiveProjectKey(null);
    setTab("chat");
    void clearWorkspaceContext();
  }

  function switchProject(projectKey: string) {
    if (!activeTenantId) {
      return;
    }
    setActiveProjectKey(projectKey);
    setTab("chat");
    void saveWorkspaceContext({ tenantId: activeTenantId, projectKey });
  }

  if (booting) {
    return (
      <div className="splash" data-testid="app-booting">
        <span className="spinner" aria-hidden="true" />
        <span>Loading…</span>
      </div>
    );
  }

  if (!session) {
    return authView === "register" ? (
      <RegisterScreen
        onSuccess={setSession}
        onNavigateLogin={() => setAuthView("login")}
      />
    ) : (
      <LoginScreen
        onSuccess={setSession}
        onNavigateRegister={() => setAuthView("register")}
      />
    );
  }

  if (profileLoading || (!me && !meError)) {
    return (
      <div className="splash" data-testid="profile-loading">
        <span className="spinner" aria-hidden="true" />
        <span>Loading your tenants and projects…</span>
      </div>
    );
  }

  if (!me) {
    return (
      <div className="workspace-screen" data-testid="profile-error-screen">
        <header className="workspace-header">
          <div className="brand">AskMyDocs</div>
          <button type="button" className="btn ghost" onClick={handleLogout}>
            Sign out
          </button>
        </header>
        <main className="workspace-main">
          <section className="workspace-card workspace-error-card">
            <p className="workspace-eyebrow">Session context</p>
            <h1>Could not load your workspaces</h1>
            <p className="error" role="alert" data-testid="me-error">
              {meError || "Could not load your profile."}
            </p>
            <button
              type="button"
              className="btn primary"
              onClick={() => setProfileAttempt((attempt) => attempt + 1)}
              data-testid="profile-retry"
            >
              Try again
            </button>
          </section>
        </main>
      </div>
    );
  }

  const activeTeam: Team | null =
    me.teams.find((team) => team.tenant_id === activeTenantId) ?? null;
  const activeProject =
    activeTeam?.projects.find(
      (project) => project.project_key === activeProjectKey,
    ) ?? null;

  if (!activeTeam || !activeProject) {
    return (
      <WorkspaceScreen
        token={session.token}
        user={session.user}
        teams={me.teams}
        initialTenantId={activeTeam?.tenant_id ?? null}
        onConfirm={selectWorkspace}
        onLogout={handleLogout}
      />
    );
  }

  const role = primaryRole(me.roles);

  return (
    <div className="shell" data-testid="app-shell">
      <header className="topbar">
        <div className="brand" data-testid="active-tenant-brand">
          <TenantLogo
            token={session.token}
            tenantName={activeTeam.name}
            logoUrl={activeTeam.logo_url}
            className="topbar-logo"
          />
          <span>{activeTeam.name}</span>
        </div>
        <nav className="tabs" aria-label="Sections">
          <button
            type="button"
            className={tab === "chat" ? "tab active" : "tab"}
            onClick={() => setTab("chat")}
            aria-current={tab === "chat"}
            data-testid="tab-chat"
          >
            Chat
          </button>
          <button
            type="button"
            className={tab === "search" ? "tab active" : "tab"}
            onClick={() => setTab("search")}
            aria-current={tab === "search"}
            data-testid="tab-search"
          >
            Search
          </button>
        </nav>
        <div className="topbar-end">
          {me.teams.length > 0 && (
            <label className="team-switch context-switch">
              <span className="visually-hidden">Active team</span>
              <select
                className="team-select"
                value={activeTenantId ?? ""}
                onChange={(e) => switchTeam(e.target.value)}
                aria-label="Active team"
                data-testid="team-switcher"
              >
                {me.teams.map((team) => (
                  <option key={team.tenant_id} value={team.tenant_id}>
                    {team.name}
                  </option>
                ))}
              </select>
            </label>
          )}
          <label className="team-switch context-switch">
            <span className="visually-hidden">Active project</span>
            <select
              className="team-select"
              value={activeProject.project_key}
              onChange={(event) => switchProject(event.target.value)}
              aria-label="Active project"
              data-testid="project-switcher"
            >
              {activeTeam.projects.map((project) => (
                <option key={project.project_key} value={project.project_key}>
                  {project.project_key}
                </option>
              ))}
            </select>
          </label>
          <span className="badge role" data-testid="app-role" title="Your system role">
            {role}
          </span>
          <span className="user" data-testid="app-user" title={session.user.email}>
            {session.user.name}
          </span>
          <button
            type="button"
            className="btn ghost"
            onClick={handleLogout}
            data-testid="app-logout"
          >
            Sign out
          </button>
        </div>
      </header>

      <div className="teambar" data-testid="team-bar">
        <span className="teambar-label">Tenant</span>
        <span className="teambar-tenant" data-testid="team-tenant">
          {activeTeam.name}
        </span>
        <span className="teambar-sep" aria-hidden="true">
          ·
        </span>
        <span className="teambar-label">Project</span>
        <span
          className="chip active-project"
          data-testid={`team-project-${activeProject.project_key}`}
          title={`Your role: ${activeProject.role}`}
        >
          {activeProject.project_key}
          <span className="chip-role">{activeProject.role}</span>
        </span>
      </div>

      <main className="content">
        {tab === "chat" ? (
          <ChatScreen
            key={`${activeTeam.tenant_id}:${activeProject.project_key}`}
            token={session.token}
            tenantId={activeTeam.tenant_id}
            projectKey={activeProject.project_key}
          />
        ) : (
          <SearchScreen
            token={session.token}
            tenantId={activeTeam.tenant_id}
            projectKey={activeProject.project_key}
          />
        )}
      </main>
    </div>
  );
}

export default App;
