import { AdminShell } from '../admin/shell/AdminShell';
import { McpConnectionsPanel } from './McpConnectionsPanel';

export function ConnectedAppsView() {
    return (
        <AdminShell section="connected-apps">
            <div style={{ maxWidth: 1100, width: '100%', margin: '0 auto' }}>
                <McpConnectionsPanel scope="personal" />
            </div>
        </AdminShell>
    );
}
