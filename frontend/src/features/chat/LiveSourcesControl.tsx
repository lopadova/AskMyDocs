import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';
import { Button } from '../../components/Button';
import type { LiveSourceKind, LiveSourceOption, LiveSourceSelection } from './chat.api';

export interface LiveSourcesControlProps {
    sources?: Record<LiveSourceKind, LiveSourceOption[]>;
    selection?: LiveSourceSelection;
    disabled?: boolean;
    onChange: (kind: LiveSourceKind, enabledKeys: string[]) => void;
}

/** Compact per-turn controls for the agent's direct API and MCP access. */
export function LiveSourcesControl({ sources, selection, disabled = false, onChange }: LiveSourcesControlProps): ReactNode {
    if (!sources) return null;

    return (
        <div className="chat-live-sources" aria-label="Live data sources">
            <LiveSourceGroup
                kind="mcp"
                options={sources.mcp}
                enabledKeys={selection?.mcp ?? sources.mcp.map((source) => source.key)}
                disabled={disabled}
                onChange={(keys) => onChange('mcp', keys)}
            />
            <LiveSourceGroup
                kind="api"
                options={sources.api}
                enabledKeys={selection?.api ?? sources.api.map((source) => source.key)}
                disabled={disabled}
                onChange={(keys) => onChange('api', keys)}
            />
        </div>
    );
}

function LiveSourceGroup({
    kind,
    options,
    enabledKeys,
    disabled,
    onChange,
}: {
    kind: LiveSourceKind;
    options: LiveSourceOption[];
    enabledKeys: string[];
    disabled: boolean;
    onChange: (enabledKeys: string[]) => void;
}): ReactNode {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const rootRef = useRef<HTMLDivElement>(null);
    const enabled = useMemo(() => new Set(enabledKeys), [enabledKeys]);
    const visible = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();
        if (normalized === '') return options;
        return options.filter((option) =>
            `${option.name} ${option.description ?? ''} ${option.project_key ?? ''}`
                .toLocaleLowerCase()
                .includes(normalized),
        );
    }, [options, query]);

    useEffect(() => {
        if (!open) return;
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setOpen(false);
        };
        const onPointerDown = (event: MouseEvent) => {
            if (rootRef.current && !rootRef.current.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('mousedown', onPointerDown, true);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.removeEventListener('mousedown', onPointerDown, true);
        };
    }, [open]);

    if (options.length === 0) return null;

    const label = kind === 'mcp' ? 'MCP' : 'API';
    const enabledCount = options.filter((option) => enabled.has(option.key)).length;
    const allEnabled = enabledCount === options.length;
    const IconComponent = kind === 'mcp' ? Icon.Mcp : Icon.Api;

    const setOne = (key: string, nextEnabled: boolean) => {
        const next = new Set(enabled);
        if (nextEnabled) next.add(key);
        else next.delete(key);
        onChange(options.filter((option) => next.has(option.key)).map((option) => option.key));
    };

    return (
        <div className="chat-live-source-group" ref={rootRef}>
            <button
                type="button"
                className="chat-live-source-trigger"
                data-kind={kind}
                data-state={enabledCount === 0 ? 'off' : allEnabled ? 'all' : 'partial'}
                data-testid={`chat-live-source-${kind}-trigger`}
                aria-label={`${label} sources: ${enabledCount} of ${options.length} enabled`}
                aria-expanded={open}
                aria-haspopup="dialog"
                disabled={disabled}
                onClick={() => setOpen((current) => !current)}
            >
                <span className="chat-live-source-icon" aria-hidden="true"><IconComponent size={13} /></span>
                <span>{label}</span>
                <span className="chat-live-source-status" aria-hidden="true" />
                <span className="chat-live-source-count">{enabledCount}/{options.length}</span>
                <span aria-hidden="true"><Icon.ChevronDown size={11} /></span>
            </button>

            {open && (
                <div
                    className="chat-live-source-popover"
                    data-testid={`chat-live-source-${kind}-popover`}
                    role="dialog"
                    aria-label={`${label} source settings`}
                >
                    <header className="chat-live-source-popover-header">
                        <span className="chat-live-source-popover-icon" data-kind={kind} aria-hidden="true">
                            <IconComponent size={15} />
                        </span>
                        <span>
                            <strong>{label} connections</strong>
                            <small>Choose what the agent may call</small>
                        </span>
                        <Button
                            variant="quiet"
                            size="sm"
                            iconOnly
                            aria-label={`Close ${label} source settings`}
                            data-testid={`chat-live-source-${kind}-close`}
                            onClick={() => setOpen(false)}
                        >
                            <Icon.Close size={13} />
                        </Button>
                    </header>

                    {options.length > 7 && (
                        <label className="chat-live-source-search">
                            <span aria-hidden="true"><Icon.Search size={13} /></span>
                            <input
                                data-testid={`chat-live-source-${kind}-search`}
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder={`Find a ${label} connection`}
                                aria-label={`Search ${label} connections`}
                            />
                        </label>
                    )}

                    <button
                        type="button"
                        className="chat-live-source-all"
                        role="switch"
                        data-testid={`chat-live-source-${kind}-toggle-all`}
                        aria-checked={allEnabled}
                        onClick={() => onChange(allEnabled ? [] : options.map((option) => option.key))}
                    >
                        <span>
                            <strong>{allEnabled ? 'Disable all' : 'Enable all'}</strong>
                            <small>{enabledCount} of {options.length} available</small>
                        </span>
                        <Switch checked={allEnabled} />
                    </button>

                    <div className="chat-live-source-list">
                        {visible.map((option) => {
                            const checked = enabled.has(option.key);
                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    className="chat-live-source-row"
                                    data-testid={`chat-live-source-option-${option.key}`}
                                    role="switch"
                                    aria-checked={checked}
                                    onClick={() => setOne(option.key, !checked)}
                                >
                                    <span className="chat-live-source-row-copy">
                                        <strong>{option.name}</strong>
                                        <small>
                                            {option.tool_count} {option.tool_count === 1 ? 'tool' : 'tools'}
                                            {option.project_key ? ` · ${option.project_key}` : ''}
                                        </small>
                                    </span>
                                    <Switch checked={checked} />
                                </button>
                            );
                        })}
                        {visible.length === 0 && (
                            <div className="chat-live-source-empty">No matching connections.</div>
                        )}
                    </div>
                    <footer>Applies to the next message</footer>
                </div>
            )}
        </div>
    );
}

function Switch({ checked }: { checked: boolean }): ReactNode {
    return (
        <span className="chat-live-source-switch" data-checked={checked ? 'true' : 'false'} aria-hidden="true">
            <span />
        </span>
    );
}
