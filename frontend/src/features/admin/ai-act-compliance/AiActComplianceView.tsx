import { AdminShell } from '../shell/AdminShell';

export function AiActComplianceView() {
    return (
        <AdminShell section="ai-act-compliance">
            <main
                data-testid="admin-ai-act-compliance"
                data-state="ready"
                aria-busy="false"
                aria-label="AI Act compliance"
                style={{
                    flex: 1,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: 'var(--fg-1)',
                    fontFamily: 'var(--font-sans)',
                }}
            >
                <section
                    className="panel popin"
                    aria-labelledby="admin-ai-act-compliance-title"
                    style={{
                        maxWidth: 560,
                        padding: '28px 28px 24px',
                        textAlign: 'center',
                    }}
                >
                    <div
                        aria-hidden="true"
                        style={{
                            width: 48,
                            height: 48,
                            borderRadius: 12,
                            background: 'rgba(59, 130, 246, 0.16)',
                            border: '1px solid rgba(59, 130, 246, 0.35)',
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            margin: '0 auto 14px',
                            fontSize: 20,
                            color: '#93c5fd',
                        }}
                    >
                        AI
                    </div>
                    <h1
                        id="admin-ai-act-compliance-title"
                        data-testid="admin-ai-act-compliance-title"
                        style={{
                            fontSize: 20,
                            fontWeight: 600,
                            margin: '0 0 8px',
                            letterSpacing: '-0.01em',
                        }}
                    >
                        AI Act compliance scaffold
                    </h1>
                    <p
                        data-testid="admin-ai-act-compliance-summary"
                        style={{
                            fontSize: 13.5,
                            color: 'var(--fg-2)',
                            margin: '0 0 12px',
                            lineHeight: 1.55,
                        }}
                    >
                        The host-side compliance gates, DSAR services, and provenance persistence are wired. The full v6
                        dashboard package is still pending its Laravel 13 compatible release.
                    </p>
                    <div
                        data-testid="admin-ai-act-compliance-phase"
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 8,
                            padding: '6px 12px',
                            borderRadius: 999,
                            background: 'rgba(59, 130, 246, 0.1)',
                            color: '#bfdbfe',
                            fontSize: 12,
                            fontWeight: 600,
                            letterSpacing: '0.02em',
                        }}
                    >
                        v6 scaffold ready
                    </div>
                </section>
            </main>
        </AdminShell>
    );
}
