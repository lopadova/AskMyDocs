import { Link } from '@tanstack/react-router';
import { EditorialHero } from '../../components/editorial/EditorialHero';
import { Icon } from '../../components/Icons';
import { useUiTranslation } from '../../lib/ui-locale';
import { selectCurrentHash, useTeamStore } from '../../lib/team-store';
import { MeDashboard } from '../dashboard/MeDashboard';

export function WorkspaceHome() {
    const { t } = useUiTranslation();
    const teamHash = useTeamStore(selectCurrentHash);
    return <div className="editorial-page workspace-home">
        <EditorialHero artwork="knowledge" eyebrow={t('My workspace')} title={t('Your next chapter starts here.')} description={t('Ask, explore, and build on what your team knows.')}>
            {teamHash && <><Link className="editorial-action" to="/app/$teamHash/chat" params={{ teamHash }}><Icon.Chat size={17} />{t('Ask a question')}</Link><Link className="editorial-action editorial-action--quiet" to="/app/$teamHash/knowledge" params={{ teamHash }}><Icon.Book size={17} />{t('Explore the library')}</Link></>}
        </EditorialHero>
        <MeDashboard />
    </div>;
}
