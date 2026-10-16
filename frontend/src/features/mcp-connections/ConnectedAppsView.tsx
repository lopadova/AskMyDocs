import { useUiTranslation } from "../../lib/ui-locale";
import { AdminShell } from '../admin/shell/AdminShell';
import { McpConnectionsPanel } from './McpConnectionsPanel';
import { EditorialHero } from '../../components/editorial/EditorialHero';

export function ConnectedAppsView() {
    const { t: translateInterface } = useUiTranslation();
    return (
        <AdminShell section="connected-apps">
            <div className="personal-apps-page">
                <EditorialHero artwork="connections" compact eyebrow={translateInterface('My workspace')} title={translateInterface('Personal apps')} description={translateInterface("Review and revoke applications authorised to use your AskMyDocs API.")}>
                    <a className="editorial-action editorial-action--quiet" href="/oauth/connections">{translateInterface("Manage API connections")}</a>
                </EditorialHero>
                <McpConnectionsPanel scope="personal" />
            </div>
        </AdminShell>
    );
}
