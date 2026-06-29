import { useCallback, useEffect, useState } from "react";
import { ApiError, fetchMe, logout as apiLogout } from "./lib/api";
import {
  clearSession,
  loadActiveTenant,
  loadSession,
  saveActiveTenant,
  type Session,
} from "./lib/store";
import type { MePayload, Team } from "./lib/types";
import { ChatScreen } from "./screens/ChatScreen";
import { LoginScreen } from "./screens/LoginScreen";
import { RegisterScreen } from "./screens/RegisterScreen";
import { SearchScreen } from "./screens/SearchScreen";
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
    setMeError("");
    fetchMe(session.token)
      .then(async (payload) => {
        if (cancelled) {
          return;
        }
        setMe(payload);
        const stored = await loadActiveTenant();
        const valid = payload.teams.find((t) => t.tenant_id === stored);
        setActiveTenantId(valid?.tenant_id ?? payload.teams[0]?.tenant_id ?? null);
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
      });
    return () => {
      cancelled = true;
    };
  }, [session, handleLogout]);

  function switchTeam(tenantId: string) {
    setActiveTenantId(tenantId);
    void saveActiveTenant(tenantId);
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

  const activeTeam: Team | null =
    me?.teams.find((t) => t.tenant_id === activeTenantId) ?? me?.teams[0] ?? null;
  const role = me ? primaryRole(me.roles) : "…";

  return (
    <div className="shell" data-testid="app-shell">
      <header className="topbar">
        <div className="brand">AskMyDocs</div>
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

      {activeTeam && (
        <div className="teambar" data-testid="team-bar">
          <span className="teambar-label">Tenant</span>
          <span className="teambar-tenant" data-testid="team-tenant">
            {activeTeam.name}
          </span>
          <span className="teambar-sep" aria-hidden="true">
            ·
          </span>
          <span className="teambar-label">Projects</span>
          {activeTeam.projects.length === 0 ? (
            <span className="muted small" data-testid="team-no-projects">
              none in this tenant
            </span>
          ) : (
            <span className="teambar-projects" data-testid="team-projects">
              {activeTeam.projects.map((project) => (
                <span
                  key={project.project_key}
                  className="chip"
                  data-testid={`team-project-${project.project_key}`}
                  title={`Your role: ${project.role}`}
                >
                  {project.project_key}
                  <span className="chip-role">{project.role}</span>
                </span>
              ))}
            </span>
          )}
        </div>
      )}

      {meError && (
        <p className="error banner" role="alert" data-testid="me-error">
          {meError}
        </p>
      )}

      <main className="content">
        {tab === "chat" ? (
          <ChatScreen token={session.token} tenantId={activeTenantId ?? undefined} />
        ) : (
          <SearchScreen
            token={session.token}
            tenantId={activeTenantId ?? undefined}
          />
        )}
      </main>
    </div>
  );
}

export default App;
