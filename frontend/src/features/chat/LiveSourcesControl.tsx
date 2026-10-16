import { useUiTranslation } from "../../lib/ui-locale";
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import * as Popover from '@radix-ui/react-popover';
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
    const { t } = useUiTranslation();
    if (!sources) return null;

    return (
        <div className="chat-live-sources" aria-label={t('Live data sources')}>
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
    const { t: translateInterface } = useUiTranslation();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
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
        if (disabled) setOpen(false);
    }, [disabled]);

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
        <Popover.Root open={open && !disabled} onOpenChange={setOpen}>
            <div className="chat-live-source-group">
                <Popover.Trigger asChild>
                    <button
                        type="button"
                        className="chat-live-source-trigger"
                        data-kind={kind}
                        data-state={enabledCount === 0 ? 'off' : allEnabled ? 'all' : 'partial'}
                        data-testid={`chat-live-source-${kind}-trigger`}
                        aria-label={translateInterface('{kind} sources: {count} of {total} enabled', { kind: label, count: enabledCount, total: options.length })}
                        aria-expanded={open && !disabled}
                        aria-haspopup="dialog"
                        disabled={disabled}
                    >
                        <span className="chat-live-source-icon" aria-hidden="true"><IconComponent size={13} /></span>
                        <span>{label}</span>
                        <span className="chat-live-source-status" aria-hidden="true" />
                        <span className="chat-live-source-count">{enabledCount}/{options.length}</span>
                        <span aria-hidden="true"><Icon.ChevronDown size={11} /></span>
                    </button>
                </Popover.Trigger>

                <Popover.Portal>
                    <Popover.Content
                        className="chat-live-source-popover"
                        data-testid={`chat-live-source-${kind}-popover`}
                        aria-label={translateInterface('{kind} source settings', { kind: label })}
                        side="top"
                        align="end"
                        sideOffset={9}
                        collisionPadding={14}
                        hideWhenDetached
                    >
                        <header className="chat-live-source-popover-header">
                            <span className="chat-live-source-popover-icon" data-kind={kind} aria-hidden="true">
                                <IconComponent size={15} />
                            </span>
                            <span>
                                <strong>{translateInterface('{kind} connections', { kind: label })}</strong>
                                <small>{translateInterface('Choose what the agent may call')}</small>
                            </span>
                            <Popover.Close asChild><Button
                                variant="quiet"
                                size="sm"
                                iconOnly
                                aria-label={translateInterface('Close {kind} source settings', { kind: label })}
                                data-testid={`chat-live-source-${kind}-close`}
                            >
                                <Icon.Close size={13} />
                            </Button></Popover.Close>
                        </header>

                        {options.length > 7 && (
                            <label className="chat-live-source-search">
                                <span aria-hidden="true"><Icon.Search size={13} /></span>
                                <input
                                    data-testid={`chat-live-source-${kind}-search`}
                                    type="search"
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder={translateInterface('Find a {kind} connection', { kind: label })}
                                    aria-label={translateInterface('Search {kind} connections', { kind: label })}
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
                                <strong>{translateInterface(allEnabled ? 'Disable all' : 'Enable all')}</strong>
                                <small>{translateInterface('{count} of {total} available', { count: enabledCount, total: options.length })}</small>
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
                                <div className="chat-live-source-empty">{translateInterface('No matching connections.')}</div>
                            )}
                        </div>
                        <footer>{translateInterface('Applies to the next message')}</footer>
                    </Popover.Content>
                </Popover.Portal>
            </div>
        </Popover.Root>
    );
}

function Switch({ checked }: { checked: boolean }): ReactNode {
    return (
        <span className="chat-live-source-switch" data-checked={checked ? 'true' : 'false'} aria-hidden="true">
            <span />
        </span>
    );
}
