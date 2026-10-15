import * as Dialog from '@radix-ui/react-dialog';
import { Button } from '../Button';
import { Icon } from '../Icons';
import { SegmentedControl } from './SegmentedControl';
import { useUiTranslation } from '../../lib/ui-locale';
import type { Density, FontPair, Theme } from './hooks';
import type { SidebarSection } from './Sidebar';
import type { SeedUser } from '../../lib/seed';

export type TweaksPanelProps = {
    open: boolean; onClose: () => void; theme: Theme; setTheme: (theme: Theme) => void;
    density: Density; setDensity: (density: Density) => void; font: FontPair; setFont: (font: FontPair) => void;
    section: SidebarSection; setSection: (section: SidebarSection) => void; userRole?: SeedUser['role'];
};

export function TweaksPanel({ open, onClose, theme, setTheme, density, setDensity, font, setFont }: TweaksPanelProps) {
    const { t } = useUiTranslation();
    return <Dialog.Root open={open} onOpenChange={(value) => { if (!value) onClose(); }}>
        <Dialog.Portal><Dialog.Overlay className="palette-overlay" /><Dialog.Content className="interface-preferences" aria-describedby={undefined}>
            <header><Dialog.Title>{t('Interface settings')}</Dialog.Title><Dialog.Close asChild><Button variant="quiet" size="sm" iconOnly aria-label={t('Close')}><Icon.Close size={18} /></Button></Dialog.Close></header>
            <fieldset><legend>{t('Theme')}</legend><SegmentedControl<Theme> options={[{ v: 'dark', l: t('Dark') }, { v: 'light', l: t('Light') }]} value={theme} onChange={setTheme} /></fieldset>
            <fieldset><legend>{t('Density')}</legend><SegmentedControl<Density> options={[{ v: 'compact', l: t('Compact') }, { v: 'balanced', l: t('Balanced') }, { v: 'comfortable', l: t('Comfortable') }]} value={density} onChange={setDensity} /></fieldset>
            <fieldset><legend>{t('Typography')}</legend><SegmentedControl<FontPair> options={[{ v: 'geist', l: 'Geist' }, { v: 'inter', l: 'Inter' }, { v: 'plex', l: 'Plex' }]} value={font} onChange={setFont} /></fieldset>
        </Dialog.Content></Dialog.Portal>
    </Dialog.Root>;
}
