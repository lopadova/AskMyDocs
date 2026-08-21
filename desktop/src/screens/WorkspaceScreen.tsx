import { useState } from "react";
import { TenantLogo } from "../components/TenantLogo";
import type { Team } from "../lib/types";

interface Props {
  token: string;
  teams: Team[];
  initialTenantId?: string | null;
  onSelect: (tenantId: string, projectKey: string) => void;
  onLogout: () => void;
}

export function WorkspaceScreen({ token, teams, initialTenantId, onSelect, onLogout }: Props) {
  const initial = teams.find((team) => team.tenant_id === initialTenantId) ?? null;
  const [tenant, setTenant] = useState<Team | null>(initial);

  return (
    <main className="workspace" aria-labelledby="workspace-title" data-testid="workspace-screen">
      <section className="workspace-card">
        <header className="workspace-header">
          <div>
            <h1 id="workspace-title">{tenant ? "Choose a project" : "Choose a tenant"}</h1>
            <p className="muted">
              {tenant ? `You are entering ${tenant.name}.` : "Select the organisation you want to work with."}
            </p>
          </div>
          <button type="button" className="btn ghost" onClick={onLogout} data-testid="workspace-logout">Sign out</button>
        </header>

        {!tenant && teams.length === 0 && (
          <p className="error banner" role="alert" data-testid="workspace-no-tenants">
            Your account is not assigned to any tenant.
          </p>
        )}

        {!tenant && (
          <div className="workspace-grid" data-testid="workspace-tenants">
            {teams.map((team) => (
              <button
                type="button"
                className="workspace-option tenant-option"
                key={team.tenant_id}
                onClick={() => setTenant(team)}
                data-testid={`workspace-tenant-${team.tenant_id}`}
              >
                <TenantLogo token={token} tenantName={team.name} logoUrl={team.logo_url} className="workspace-logo" />
                <span className="workspace-option-copy">
                  <strong>{team.name}</strong>
                  <span className="muted small">{team.projects.length} project{team.projects.length === 1 ? "" : "s"}</span>
                </span>
                <span aria-hidden="true">›</span>
              </button>
            ))}
          </div>
        )}

        {tenant && (
          <>
            <button type="button" className="workspace-back" onClick={() => setTenant(null)} data-testid="workspace-back-tenants">
              ← Change tenant
            </button>
            <div className="workspace-tenant-heading">
              <TenantLogo token={token} tenantName={tenant.name} logoUrl={tenant.logo_url} className="workspace-logo" />
              <div><strong>{tenant.name}</strong><div className="muted small">{tenant.tenant_id}</div></div>
            </div>
            {tenant.projects.length === 0 ? (
              <p className="muted" data-testid="workspace-no-projects">No projects are available in this tenant.</p>
            ) : (
              <div className="workspace-grid" data-testid="workspace-projects">
                {tenant.projects.map((project) => (
                  <button
                    type="button"
                    className="workspace-option"
                    key={project.project_key}
                    onClick={() => onSelect(tenant.tenant_id, project.project_key)}
                    data-testid={`workspace-project-${project.project_key}`}
                  >
                    <span className="project-mark" aria-hidden="true">◆</span>
                    <span className="workspace-option-copy">
                      <strong>{project.project_key}</strong>
                      <span className="muted small">Role: {project.role}</span>
                    </span>
                    <span aria-hidden="true">›</span>
                  </button>
                ))}
              </div>
            )}
          </>
        )}
      </section>
    </main>
  );
}
