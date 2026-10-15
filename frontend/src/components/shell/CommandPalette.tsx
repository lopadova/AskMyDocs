import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import * as Dialog from '@radix-ui/react-dialog';
import { Icon } from '../Icons';
import { Button } from '../Button';
import { useAuthStore } from '../../lib/auth-store';
import { selectCurrentHash, useTeamStore } from '../../lib/team-store';
import { useUiTranslation } from '../../lib/ui-locale';
import { hubForSection, navHref, navLabel, searchNavigation } from './navigation';

export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(0);
    const { t } = useUiTranslation();
    const navigate = useNavigate();
    const roles = useAuthStore((state) => state.roles);
    const permissions = useAuthStore((state) => state.permissions);
    const features = useAuthStore((state) => state.features);
    const teamHash = useTeamStore(selectCurrentHash);
    const results = useMemo(() => searchNavigation(query, { roles, permissions, features, hasTenant: teamHash !== null }, t), [query, roles, permissions, features, teamHash, t]);
    useEffect(() => {
        const trigger = () => setOpen(true);
        const key = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); setOpen((current) => !current); }
        };
        window.addEventListener('amd:palette', trigger);
        window.addEventListener('keydown', key);
        return () => { window.removeEventListener('amd:palette', trigger); window.removeEventListener('keydown', key); };
    }, []);
    useEffect(() => { if (!open) { setQuery(''); setSelected(0); } }, [open]);
    const choose = (index: number) => {
        const item = results[index];
        if (!item) return;
        setOpen(false);
        void navigate({ to: navHref(item, teamHash) });
    };
    return <Dialog.Root open={open} onOpenChange={setOpen}>
        <Dialog.Portal><Dialog.Overlay className="palette-overlay" /><Dialog.Content className="navigation-palette" aria-describedby={undefined}>
            <Dialog.Title className="sr-only">{t('Search pages and tools')}</Dialog.Title>
            <div className="navigation-palette-input"><Icon.Search size={20} /><input
                aria-label={t('Search pages and tools')} placeholder={t('Search pages and tools')} value={query}
                role="combobox" aria-expanded="true" aria-controls="navigation-search-results" aria-autocomplete="list"
                aria-activedescendant={results[selected] ? 'navigation-result-' + results[selected].id : undefined}
                onChange={(event) => { setQuery(event.target.value); setSelected(0); }}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown') { event.preventDefault(); setSelected((current) => Math.min(current + 1, results.length - 1)); }
                    if (event.key === 'ArrowUp') { event.preventDefault(); setSelected((current) => Math.max(current - 1, 0)); }
                    if (event.key === 'Enter') { event.preventDefault(); choose(selected); }
                }} />
                <Dialog.Close asChild><Button variant="quiet" size="sm" iconOnly aria-label={t('Close command palette')}><Icon.Close size={18} /></Button></Dialog.Close>
            </div>
            <div id="navigation-search-results" className="navigation-search-results" role="listbox" aria-label={t('Available pages')}>
                {results.length === 0 && <div className="navigation-search-empty"><h2>{t('No matching pages.')}</h2><p>{t('Try a different name or keyword.')}</p></div>}
                {results.map((item, index) => {
                    const ItemIcon = Icon[item.icon];
                    return <Link role="option" aria-selected={selected === index} id={'navigation-result-' + item.id} key={item.id} to={navHref(item, teamHash)} onMouseEnter={() => setSelected(index)} onClick={() => setOpen(false)}>
                        <ItemIcon size={18} /><span>{t(navLabel(item))}<small>{t(hubForSection(item.id)?.label ?? 'My workspace')}</small></span><Icon.Chevron size={16} />
                    </Link>;
                })}
            </div>
            <div className="navigation-palette-footer"><span>{t('Navigate')} <kbd>↑ ↓</kbd></span><span>{t('Open')} <kbd>Enter</kbd></span><span>{t('Close')} <kbd>Esc</kbd></span></div>
        </Dialog.Content></Dialog.Portal>
    </Dialog.Root>;
}
