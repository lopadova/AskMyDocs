import { Button } from "../components/Button";
import { Icon } from "../components/Icon";
import { useState } from "react";
import { TenantLogo } from "../components/TenantLogo";
import type { AuthUser, Team } from "../lib/types";

interface Props {
  token: string;
  user: AuthUser;
  teams: Team[];
  initialTenantId?: string | null;
  onConfirm: (tenantId: string, projectKey: string) => void;
  onLogout: () => void;
}

type Step = "tenant" | "project";

export function WorkspaceScreen({
  token,
  user,
  teams,
  initialTenantId = null,
  onConfirm,
  onLogout,
}: Props) {
  const initialTeam = teams.find((team) => team.tenant_id === initialTenantId);
  const [step, setStep] = useState<Step>(initialTeam ? "project" : "tenant");
  const [tenantId, setTenantId] = useState(initialTeam?.tenant_id ?? "");
  const [projectKey, setProjectKey] = useState("");

  const selectedTeam = teams.find((team) => team.tenant_id === tenantId) ?? null;
  const selectedProject =
    selectedTeam?.projects.find((project) => project.project_key === projectKey) ??
    null;

  function chooseTenant(nextTenantId: string) {
    setTenantId(nextTenantId);
    setProjectKey("");
  }

  function continueToProjects() {
    if (selectedTeam) {
      setStep("project");
    }
  }

  function openProject() {
    if (selectedTeam && selectedProject) {
      onConfirm(selectedTeam.tenant_id, selectedProject.project_key);
    }
  }

  return (
    <div className="workspace-screen" data-testid="workspace-screen">
      <header className="workspace-header">
        <div className="brand">AskMyDocs</div>
        <div className="workspace-user">
          <span className="muted" title={user.email}>
            {user.name}
          </span>
          <Button
            type="button"
            variant="quiet"
            onClick={onLogout}
            data-testid="workspace-logout"
          >
            Sign out
          </Button>
        </div>
      </header>

      <main className="workspace-main">
        <section className="workspace-card" aria-labelledby="workspace-title">
          <div className="workspace-progress" aria-label="Workspace selection steps">
            <span className="workspace-step active">
              <span className="workspace-step-number">1</span>
              Tenant
            </span>
            <span className="workspace-progress-line" aria-hidden="true" />
            <span
              className={
                step === "project" ? "workspace-step active" : "workspace-step"
              }
            >
              <span className="workspace-step-number">2</span>
              Project
            </span>
          </div>

          {step === "tenant" ? (
            <>
              <div className="workspace-copy">
                <p className="workspace-eyebrow">Session context</p>
                <h1 id="workspace-title">Choose a tenant</h1>
                <p className="muted">
                  Select the organization whose knowledge base you want to use.
                </p>
              </div>

              {teams.length === 0 ? (
                <div className="workspace-empty" data-testid="workspace-no-tenants">
                  <strong>No tenants available</strong>
                  <span className="muted small">
                    Your account has not been assigned to a tenant yet.
                  </span>
                </div>
              ) : (
                <div
                  className="workspace-options"
                  data-testid="workspace-tenants"
                >
                  {teams.map((team) => (
                    <button
                      type="button"
                      key={team.tenant_id}
                      className={
                        team.tenant_id === tenantId
                          ? "workspace-option selected"
                          : "workspace-option"
                      }
                      onClick={() => chooseTenant(team.tenant_id)}
                      aria-pressed={team.tenant_id === tenantId}
                      data-testid={`workspace-tenant-${team.tenant_id}`}
                    >
                      <TenantLogo
                        token={token}
                        tenantName={team.name}
                        logoUrl={team.logo_url}
                        className="workspace-logo"
                      />
                      <span className="workspace-option-copy">
                        <strong>{team.name}</strong>
                        <span className="muted small">
                          {team.projects.length}{" "}
                          {team.projects.length === 1 ? "project" : "projects"}
                        </span>
                      </span>
                      <span className="workspace-option-arrow" aria-hidden="true">
                        <Icon name="arrow" />
                      </span>
                    </button>
                  ))}
                </div>
              )}

              <div className="workspace-actions">
                <Button
                  type="button"
                  variant="primary"
                  disabled={!selectedTeam}
                  onClick={continueToProjects}
                  data-testid="workspace-tenant-continue"
                >
                  Continue
                </Button>
              </div>
            </>
          ) : (
            <>
              <div className="workspace-tenant-heading">
                {selectedTeam && (
                  <TenantLogo
                    token={token}
                    tenantName={selectedTeam.name}
                    logoUrl={selectedTeam.logo_url}
                    className="workspace-logo"
                  />
                )}
                <div className="workspace-copy">
                  <p className="workspace-eyebrow">{selectedTeam?.name}</p>
                  <h1 id="workspace-title">Choose a project</h1>
                  <p className="muted">
                    Chat, search, sources, and local conversations will use this
                    project.
                  </p>
                </div>
              </div>

              {!selectedTeam || selectedTeam.projects.length === 0 ? (
                <div
                  className="workspace-empty"
                  data-testid="workspace-no-projects"
                >
                  <strong>No projects available</strong>
                  <span className="muted small">
                    Choose another tenant or ask an administrator for project
                    access.
                  </span>
                </div>
              ) : (
                <div
                  className="workspace-options"
                  data-testid="workspace-projects"
                >
                  {selectedTeam.projects.map((project) => (
                    <button
                      type="button"
                      key={project.project_key}
                      className={
                        project.project_key === projectKey
                          ? "workspace-option selected"
                          : "workspace-option"
                      }
                      onClick={() => setProjectKey(project.project_key)}
                      aria-pressed={project.project_key === projectKey}
                      data-testid={`workspace-project-${project.project_key}`}
                    >
                      <span
                        className="workspace-option-mark project"
                        aria-hidden="true"
                      >
                        <Icon name="folder" />
                      </span>
                      <span className="workspace-option-copy">
                        <strong>{project.project_key}</strong>
                        <span className="muted small">Role: {project.role}</span>
                      </span>
                      <span className="workspace-option-arrow" aria-hidden="true">
                        <Icon name="arrow" />
                      </span>
                    </button>
                  ))}
                </div>
              )}

              <div className="workspace-actions split">
                <Button
                  type="button"
                  variant="quiet"
                  onClick={() => {
                    setProjectKey("");
                    setStep("tenant");
                  }}
                  data-testid="workspace-project-back"
                >
                  Back
                </Button>
                <Button
                  type="button"
                  variant="primary"
                  disabled={!selectedProject}
                  onClick={openProject}
                  data-testid="workspace-project-open"
                >
                  Open project
                </Button>
              </div>
            </>
          )}
        </section>
      </main>
    </div>
  );
}
