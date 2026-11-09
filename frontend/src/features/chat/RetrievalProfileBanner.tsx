import { Link } from '@tanstack/react-router';
import { useAuthStore } from '../../lib/auth-store';
import { selectCurrentHash, useTeamStore } from '../../lib/team-store';
import { useUiTranslation } from '../../lib/ui-locale';
import './retrieval-profile-banner.css';
import { useRetrievalProfileStatus } from './use-retrieval-profile-status';

export function RetrievalProfileBanner({ projectKeys }: { projectKeys: string[] }) {
    const { t } = useUiTranslation();
    const teamHash = useTeamStore(selectCurrentHash);
    const roles = useAuthStore((s) => s.roles);
    const canConfigure = roles.includes('admin') || roles.includes('super-admin');
    const { missing } = useRetrievalProfileStatus(projectKeys);
    if (missing.length === 0) return null;

    return (
        <aside className="retrieval-profile-banner" role="alert" data-testid="retrieval-profile-banner">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="m10.3 3.9-8 14a2 2 0 0 0 1.7 3h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0Z" />
                <path d="M12 9v4m0 4h.01" />
            </svg>
            <div className="retrieval-profile-banner-copy">
                <strong>{t('Research profile required')}</strong>
                <p>{t('Document search is unavailable until the research profile is completed for: {projects}.', { projects: missing.map((p) => p.project_key).join(', ') })}</p>
                {!canConfigure && <p>{t('Ask a workspace administrator to complete the profile.')}</p>}
            </div>
            {canConfigure && teamHash && (
                <Link className="ui-button" data-variant="secondary" data-size="sm"
                    to="/app/$teamHash/admin/kb/retrieval-profiles" params={{ teamHash }}
                    search={{ project: missing[0].project_key }}>
                    {t('Complete research profile')}
                </Link>
            )}
        </aside>
    );
}
