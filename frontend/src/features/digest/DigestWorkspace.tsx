import { EditorialArtwork } from '../../components/editorial/EditorialArtwork';
import { useUiTranslation } from '../../lib/ui-locale';
import { DigestFeedCard } from './DigestFeedCard';
import { DigestPreferences } from './DigestPreferences';
import './digest.css';

/** The personal updates page shares the editorial rhythm of the workspace. */
export function DigestWorkspace() {
    const { t } = useUiTranslation();

    return <div className="digest-workspace">
        <header className="digest-workspace-heading editorial-surface">
            <EditorialArtwork artwork="community" className="editorial-surface-art" resolution="small" />
            <div className="digest-workspace-heading-copy">
                <p className="digest-eyebrow">{t('My workspace')}</p>
                <h1>{t('Updates')}</h1>
                <p>{t('A clear view of what changed in your knowledge base.')}</p>
            </div>
        </header>
        <div className="digest-workspace-grid">
            <DigestFeedCard />
            <DigestPreferences />
        </div>
    </div>;
}
