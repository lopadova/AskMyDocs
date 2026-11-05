import { useId, useRef, useState } from 'react';
import { ChevronDown, Search } from 'lucide-react';
import { Button } from '../../../components/Button';
export interface PickerOption { value: string; label: string; detail?: string }
export function SearchPicker({ label, value, options, onChange, placeholder = 'Seleziona' }: { label: string; value: string; options: PickerOption[]; onChange: (value: string) => void; placeholder?: string }) {
    const id = useId();
    const trigger = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const filtered = options.filter((option) => `${option.label} ${option.detail ?? ''}`.toLowerCase().includes(query.toLowerCase()));
    function choose(next: string) { onChange(next); setOpen(false); setQuery(''); setActive(0); trigger.current?.focus(); }
    return <div className="api-tool-picker" onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false); }}>
        <Button ref={trigger} aria-label={label} aria-haspopup="listbox" aria-expanded={open} trailingIcon={<ChevronDown size={14} />} onClick={() => { setOpen(!open); setQuery(''); setActive(0); trigger.current?.focus(); }}>{options.find((option) => option.value === value)?.label ?? (value || placeholder)}</Button>
        {open && <div className="api-tool-picker-menu">
            <div className="api-tool-picker-search"><Search size={15} aria-hidden="true" /><input autoFocus role="combobox" aria-label={`Cerca ${label}`} aria-controls={id} aria-expanded="true" aria-autocomplete="list" aria-activedescendant={filtered[active] ? `${id}-${active}` : undefined} value={query} onChange={(event) => { setQuery(event.target.value); setActive(0); }} onKeyDown={(event) => {
                if (event.key === 'Escape') { event.preventDefault(); setOpen(false); trigger.current?.focus(); }
                if (event.key === 'ArrowDown') { event.preventDefault(); setActive(Math.min(active + 1, filtered.length - 1)); }
                if (event.key === 'ArrowUp') { event.preventDefault(); setActive(Math.max(active - 1, 0)); }
                if (event.key === 'Enter' && filtered[active]) { event.preventDefault(); choose(filtered[active].value); }
            }} /></div>
            <div role="listbox" id={id} aria-label={label}>{filtered.map((option, index) => <Button role="option" aria-selected={option.value === value} id={`${id}-${index}`} key={option.value} variant="quiet" className={active === index ? 'is-active-option' : ''} onMouseEnter={() => setActive(index)} onClick={() => choose(option.value)}><span>{option.label}</span>{option.detail && <small>{option.detail}</small>}</Button>)}{filtered.length === 0 && <p>Nessun risultato</p>}</div>
        </div>}
    </div>;
}
