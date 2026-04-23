export type SegmentedOption<T extends string> = { v: T; l: string };

export type SegmentedControlProps<T extends string> = {
    options: SegmentedOption<T>[];
    value: T;
    onChange: (v: T) => void;
    small?: boolean;
};

export function SegmentedControl<T extends string>({
    options,
    value,
    onChange,
    small,
}: SegmentedControlProps<T>) {
    return (
        <div
            style={{
                display: 'flex',
                background: 'var(--bg-2)',
                borderRadius: 8,
                padding: 3,
                border: '1px solid var(--panel-border)',
            }}
            role="radiogroup"
        >
            {options.map((o) => {
                const active = value === o.v;
                return (
                    <button
                        key={o.v}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(o.v)}
                        style={{
                            flex: 1,
                            padding: small ? '5px 6px' : '6px 10px',
                            border: 0,
                            borderRadius: 6,
                            background: active ? 'var(--bg-4)' : 'transparent',
                            color: active ? 'var(--fg-0)' : 'var(--fg-2)',
                            fontSize: small ? 11 : 12,
                            fontWeight: 500,
                            cursor: 'pointer',
                            boxShadow: active ? 'var(--shadow-sm)' : 'none',
                            transition: 'all .15s',
                        }}
                    >
                        {o.l}
                    </button>
                );
            })}
        </div>
    );
}
