import { AdminShell } from '../admin/shell/AdminShell';
import { McpConnectionsPanel } from './McpConnectionsPanel';

export function ConnectedAppsView() {
    return (
        <AdminShell section="connected-apps">
            <div style={{ maxWidth: 1100, width: '100%', margin: '0 auto' }}>
                <section style={{ marginBottom: 24 }}>
                    <h2 style={{ fontSize: 18 }}>External applications</h2>
                    <p style={{ color: 'var(--fg-2)', fontSize: 13 }}>Review and revoke applications authorised to use your AskMyDocs API.</p>
                    <a href="/oauth/connections">Manage API connections</a>
                </section>
                <McpConnectionsPanel scope="personal" />
            </div>
        </AdminShell>
    );
}
