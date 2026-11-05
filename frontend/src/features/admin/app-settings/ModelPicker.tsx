import { useEffect, useId, useRef, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { Check, ChevronDown, Search } from 'lucide-react';
import { Button } from '../../../components/Button';
import { Input } from '../../../components/ui/input';
import { useUiTranslation } from '../../../lib/ui-locale';
import type { AiModelCatalog, AiModelOption } from './app-settings.api';

export function modelPrice(value: number | null, currency: string, locale: string): string {
    if (value === null || !Number.isFinite(value)) return 'n/d';
    return new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: 4 }).format(value);
}

interface ModelPickerProps {
    value: string;
    inheritedModel?: string | null;
    catalog?: AiModelCatalog;
    disabled?: boolean;
    loading?: boolean;
    id: string;
    label: string;
    describedBy?: string;
    onChange: (value: string) => void;
}

export function ModelPicker({ value, inheritedModel, catalog, disabled, loading, id, label, describedBy, onChange }: ModelPickerProps) {
    const { t, locale } = useUiTranslation();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [active, setActive] = useState(0);
    const listId = useId();
    const optionsRef = useRef<(HTMLButtonElement | null)[]>([]);
    const selected = catalog?.models.find((model) => model.id === (value || inheritedModel));
    const filtered = (catalog?.models ?? []).filter((model) => `${model.name} ${model.id}`.toLowerCase().includes(search.toLowerCase())).slice(0, 100);
    const options: (AiModelOption | null)[] = search.trim() ? filtered : [null, ...filtered];
    const choose = (model: AiModelOption | null) => {
        if (model && (!model.selectable || catalog?.stale)) return;
        onChange(model?.id ?? '');
        setOpen(false);
    };
    useEffect(() => { setSearch(''); setActive(0); }, [open]);
    useEffect(() => { optionsRef.current[active]?.scrollIntoView?.({ block: 'nearest' }); }, [active]);

    return <Popover.Root open={open} onOpenChange={setOpen}>
        <Popover.Trigger asChild>
            <Button id={id} variant="secondary" size="md" className="settings-model-trigger" disabled={disabled}
                role="combobox" aria-controls={open ? listId : undefined} aria-label={t(label)} aria-describedby={describedBy}
                data-testid={`${id}-input`} trailingIcon={<ChevronDown size={15} aria-hidden />}>
                <span className="settings-model-selection">
                    <strong>{loading && !catalog ? t('Loading models…') : selected?.name ?? (value || t('Use provider default'))}</strong>
                    <span>{selected ? `${modelPrice(selected.input_per_million, selected.currency, locale)} ${t('input')} · ${modelPrice(selected.output_per_million, selected.currency, locale)} ${t('output')} / 1M`
                        : value ? t('Not in the current catalog') : inheritedModel ?? t('Inherited model')}</span>
                </span>
            </Button>
        </Popover.Trigger>
        <Popover.Portal>
            <Popover.Content className="settings-model-popover" align="start" sideOffset={8} collisionPadding={16}
                aria-label={t('Choose a model')}>
                <div className="settings-model-search"><Search size={16} aria-hidden /><Input value={search}
                    aria-label={t('Search models')} placeholder={t('Search by model name or ID…')}
                    aria-controls={listId} aria-activedescendant={options.length ? `${listId}-${active}` : undefined}
                    onChange={(event) => { setSearch(event.target.value); setActive(0); }}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            setActive((current) => (current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % Math.max(1, options.length));
                        } else if (event.key === 'Enter' && options[active] !== undefined) {
                            event.preventDefault(); choose(options[active]);
                        }
                    }} /></div>
                <div className="settings-model-columns" aria-hidden><span>{t('Model')}</span><span>{t('Input / 1M')}</span><span>{t('Output / 1M')}</span></div>
                <div id={listId} role="listbox" aria-label={t('Available models')} className="settings-model-options">
                    {options.map((model, index) => <button type="button" key={model?.id ?? 'inherit'} role="option" id={`${listId}-${index}`}
                        ref={(element) => { optionsRef.current[index] = element; }} tabIndex={-1}
                        aria-selected={model ? model.id === value : value === ''}
                        disabled={model !== null && (!model.selectable || catalog?.stale)}
                        data-active={index === active} className="settings-model-option" onClick={() => choose(model)}>
                        <span className="settings-model-option-name"><strong>{model?.name ?? t('Inherit the default')}</strong>
                            <small>{model?.id ?? inheritedModel ?? t('Provider configuration')}</small>
                            {model && <small>{model.price_source ?? t('Unknown')}{model.price_updated_at ? ` · ${new Date(model.price_updated_at).toLocaleDateString(locale)}` : ''} · {model.currency}</small>}
                            {model && !model.selectable && <small>{t(model.deprecated ? 'Deprecated model' : 'Agent tools not supported')}</small>}
                        </span>
                        <span>{model ? modelPrice(model.input_per_million, model.currency, locale) : '—'}</span>
                        <span>{model ? modelPrice(model.output_per_million, model.currency, locale) : '—'}
                            {(model ? model.id === value : value === '') && <Check size={13} aria-hidden />}</span>
                    </button>)}
                    {options.length === 0 && <p className="settings-model-empty">{t('No matching models.')}</p>}
                </div>
                <div className="settings-model-footnote">{t('List prices per million tokens. Unknown prices are shown as n/d.')}
                    {filtered.length === 100 && <span>{t('Refine your search to see more models.')}</span>}</div>
            </Popover.Content>
        </Popover.Portal>
    </Popover.Root>;
}
