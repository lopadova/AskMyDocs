/*
 * Labelled <select> filter used across the read tabs. Every control carries a
 * bound <label htmlFor> (R15 — placeholder/aria is not enough) and a stable
 * testid (R11/R29). The empty value is the "all" sentinel (no server filter).
 */

export interface SelectOption {
    value: string;
    label: string;
}

export interface SelectFilterProps {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    allLabel?: string;
    testid: string;
}

export function SelectFilter({ id, label, value, onChange, options, allLabel = 'All', testid }: SelectFilterProps) {
    return (
        <label
            htmlFor={id}
            style={{ display: 'inline-flex', flexDirection: 'column', gap: 3, fontSize: 11, color: 'var(--fg-3)' }}
        >
            <span style={{ textTransform: 'uppercase', letterSpacing: '0.04em' }}>{label}</span>
            <select
                id={id}
                data-testid={testid}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                style={{
                    padding: '5px 8px',
                    borderRadius: 6,
                    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                    background: 'var(--bg-3, rgba(255,255,255,.04))',
                    color: 'var(--fg-0)',
                    fontSize: 12,
                    minWidth: 150,
                }}
            >
                <option value="">{allLabel}</option>
                {options.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                        {opt.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
