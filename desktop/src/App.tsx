import { useCallback, useEffect, useState } from "react";
import { ApiError, fetchMe, logout as apiLogout } from "./lib/api";
import {
  clearSession,
  loadActiveProject,
  loadActiveTenant,
  loadSession,
  saveActiveProject,
  saveActiveTenant,
  type Session,
} from "./lib/store";
import type { MePayload, Team } from "./lib/types";
import { ChatScreen } from "./screens/ChatScreen";
import { TenantLogo } from "./components/TenantLogo";
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
  const [loadingMe, setLoadingMe] = useState(false);
  const [meError, setMeError] = useState("");

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
  // pick the active team (persisted choice if still valid, else the first).
  useEffect(() => {
    if (!session) {
      setMe(null);
      return;
    }
    let cancelled = false;
    setLoadingMe(true);
    setMeError("");
    fetchMe(session.token)
      .then(async (payload) => {
        if (cancelled) {
          return;
        }
        const stored = await loadActiveTenant();
        if (cancelled) return;
        setMe(payload);
        const valid = payload.teams.find((t) => t.tenant_id === stored);
        if (!valid) {
          setActiveTenantId(null);
          setActiveProjectKey(null);
          return;
        }
        const storedProject = await loadActiveProject(valid.tenant_id);
        if (cancelled) return;
        const validProject = valid.projects.find((project) => project.project_key === storedProject);
        setActiveTenantId(valid.tenant_id);
        setActiveProjectKey(validProject?.project_key ?? null);
      })
      .catch((err) => {
        if (cancelled) {
          return;
        }
        if (err instanceof ApiError && err.status === 401) {
          void handleLogout();
          return;
        }
        setMeError(
          err instanceof ApiError ? err.message : "Could not load your profile.",
        );
      })
      .finally(() => {
        if (!cancelled) setLoadingMe(false);
      });
    return () => {
      cancelled = true;
    };
  }, [session, handleLogout]);

  function switchTeam(tenantId: string) {
    setActiveTenantId(tenantId);
    setActiveProjectKey(null);
    void saveActiveTenant(tenantId);
  }

  function selectWorkspace(tenantId: string, projectKey: string) {
    setActiveTenantId(tenantId);
    setActiveProjectKey(projectKey);
    void Promise.all([saveActiveTenant(tenantId), saveActiveProject(tenantId, projectKey)]);
    setTab("chat");
  }

  function switchProject(projectKey: string) {
    if (!activeTenantId) return;
    setActiveProjectKey(projectKey);
    void saveActiveProject(activeTenantId, projectKey);
    setTab("chat");
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

  if (loadingMe) {
    return (
      <div className="splash" data-testid="profile-loading">
        <span className="spinner" aria-hidden="true" />
        <span>Loading your tenants and projects…</span>
      </div>
    );
  }

  if (meError && !me) {
    return (
      <div className="auth" data-testid="profile-error-screen">
        <div className="auth-card">
          <p className="error" role="alert">{meError}</p>
          <button type="button" className="btn" onClick={handleLogout}>Sign out</button>
        </div>
      </div>
    );
  }

  const activeTeam: Team | null =
    me?.teams.find((t) => t.tenant_id === activeTenantId) ?? null;
  const role = me ? primaryRole(me.roles) : "…";

  if (me && (!activeTeam || !activeProjectKey || !activeTeam.projects.some((project) => project.project_key === activeProjectKey))) {
    return (
      <WorkspaceScreen
        token={session.token}
        teams={me.teams}
        initialTenantId={activeTenantId}
        onSelect={selectWorkspace}
        onLogout={handleLogout}
      />
    );
  }

  if (!activeTeam || !activeProjectKey) return null;

  return (
    <div className="shell" data-testid="app-shell">
      <header className="topbar">
        <div className="brand" data-testid="active-tenant-brand">
          <TenantLogo token={session.token} tenantName={activeTeam.name} logoUrl={activeTeam.logo_url} className="topbar-logo" />
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
          {me && me.teams.length > 0 && (
            <label className="team-switch">
              <span className="visually-hidden">Active team</span>
              <select
                className="team-select"
                value={activeTenantId ?? ""}
                onChange={(e) => switchTeam(e.target.value)}
                disabled={me.teams.length <= 1}
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
          <label className="team-switch">
            <span className="visually-hidden">Active project</span>
            <select
              className="team-select"
              value={activeProjectKey}
              onChange={(event) => switchProject(event.target.value)}
              aria-label="Active project"
              data-testid="project-switcher"
            >
              {activeTeam.projects.map((project) => (
                <option key={project.project_key} value={project.project_key}>{project.project_key}</option>
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
          <span className="chip" data-testid="active-project">{activeProjectKey}</span>
          <button type="button" className="link teambar-change" onClick={() => setActiveProjectKey(null)} data-testid="change-workspace">
            Change workspace
          </button>
      </div>

      {meError && (
        <p className="error banner" role="alert" data-testid="me-error">
          {meError}
        </p>
      )}

      <main className="content">
        {tab === "chat" ? (
          <ChatScreen key={`${activeTeam.tenant_id}:${activeProjectKey}`} token={session.token} tenantId={activeTeam.tenant_id} projectKey={activeProjectKey} />
        ) : (
          <SearchScreen
            token={session.token}
            tenantId={activeTeam.tenant_id}
            projectKey={activeProjectKey}
          />
        )}
      </main>
    </div>
  );
}

export default App;
