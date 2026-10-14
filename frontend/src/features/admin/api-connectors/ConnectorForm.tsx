import { useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';
import type { AdminProject } from '../projects/admin-projects.api';
import type { ApiConnector, ConnectorPayload } from './api-connectors.api';
import { SourceAvatar } from '../connectors/SourceAvatar';
import { Button } from '../../../components/Button';
import './connector-form.css';
import {
    errorTextStyle,
    fieldCaptionStyle,
    fieldLabelStyle,
    inputStyle,
    modalBackdropStyle,
    modalPanelStyle,
} from './styles';

const CLOUDFLARE_HEADER = 'X-AskMyDocs-Cloudflare-Token';

function isCloudflareHeader(name: string): boolean {
    return name.toLowerCase() === CLOUDFLARE_HEADER.toLowerCase();
}

function connectorInputStyle(): CSSProperties {
    return {
        ...inputStyle(),
        fontSize: 'var(--api-connector-field-size, 12px)',
        minHeight: 'var(--api-connector-control-height, 0px)',
    };
}

/**
 * Create / edit an API connector (the parent of routes + auth profiles).
 *
 * R11/R29: testids `api-connector-form-{field}`.
 * R15: every control has a bound `<label htmlFor>`; dialog is role=dialog +
 *      aria-modal; explicit close controls only; field errors render next to the input.
 */

export interface ConnectorFormProps {
    /** Present = edit; absent = create. */
    connector?: ApiConnector | null;
    projects: AdminProject[];
    onSubmit: (payload: ConnectorPayload) => void;
    onClose: () => void;
    submitError?: string | null;
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
}

/** Parse the `Key: Value` textarea into a headers map; blank lines ignored. */
function parseHeaders(raw: string): Record<string, string> {
    const out: Record<string, string> = {};
    for (const line of raw.split('\n')) {
        const trimmed = line.trim();
        if (trimmed === '') continue;
        const idx = trimmed.indexOf(':');
        if (idx === -1) continue;
        const key = trimmed.slice(0, idx).trim();
        const value = trimmed.slice(idx + 1).trim();
        if (key !== '') out[key] = value;
    }
    return out;
}

function stringifyHeaders(headers: Record<string, string>): string {
    return Object.entries(headers)
        .map(([k, v]) => `${k}: ${v}`)
        .join('\n');
}

export function ConnectorForm({
    connector,
    projects,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
}: ConnectorFormProps): ReactNode {
    const isEdit = !!connector;
    const [name, setName] = useState(connector?.name ?? '');
    const [description, setDescription] = useState(connector?.description ?? '');
    const [projectKey, setProjectKey] = useState(connector?.project_key ?? '');
    const [baseUrl, setBaseUrl] = useState(connector?.base_url ?? '');
    const sharedHeaders = Object.entries(connector?.headers ?? {});
    const [headersText, setHeadersText] = useState(
        stringifyHeaders(Object.fromEntries(sharedHeaders.filter(([key]) => !isCloudflareHeader(key)))),
    );
    const [cloudflareToken, setCloudflareToken] = useState(
        sharedHeaders.find(([key]) => isCloudflareHeader(key))?.[1] ?? '',
    );
    const [isActive, setIsActive] = useState(connector?.is_active ?? true);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (isSubmitting) return;
        const headers = parseHeaders(headersText);
        if (cloudflareToken.trim() !== '') {
            // Avoid duplicate values when the textarea uses different casing.
            for (const key of Object.keys(headers)) {
                if (isCloudflareHeader(key)) delete headers[key];
            }
            headers[CLOUDFLARE_HEADER] = cloudflareToken.trim();
        }
        onSubmit({
            name: name.trim(),
            description: description.trim() === '' ? null : description.trim(),
            project_key: projectKey === '' ? null : projectKey,
            base_url: baseUrl.trim() === '' ? null : baseUrl.trim(),
            headers,
            is_active: isActive,
        });
    };

    const titleId = 'api-connector-form-title';
    const cloudflareHeaderError = fieldErrors?.[`headers.${CLOUDFLARE_HEADER}`];

    return (
        <div
            data-testid="api-connector-form-backdrop"
            style={modalBackdropStyle()}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isSubmitting}
                data-testid="api-connector-form"
                className="api-connector-form"
                data-state={isSubmitting ? 'loading' : 'idle'}
                onSubmit={handleSubmit}
                style={{ ...modalPanelStyle(480), minWidth: 0, boxSizing: 'border-box' }}
            >
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <SourceAvatar connectorKey="api" displayName="API" size={38} radius={10} />
                    <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                        {isEdit ? `Edit connector: ${connector?.name}` : 'New API connector'}
                    </h2>
                </div>

                <label htmlFor="api-connector-form-name" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>
                        Name<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                    </span>
                    <input
                        id="api-connector-form-name"
                        data-testid="api-connector-form-name"
                        type="text"
                        required
                        pattern={'.*\\S.*'}
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="e.g. Weather API"
                        style={connectorInputStyle()}
                    />
                    {fieldErrors?.name && (
                        <span data-testid="api-connector-form-name-error" role="alert" style={errorTextStyle()}>
                            {fieldErrors.name}
                        </span>
                    )}
                </label>

                <label htmlFor="api-connector-form-description" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Description</span>
                    <textarea
                        id="api-connector-form-description"
                        data-testid="api-connector-form-description"
                        rows={2}
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        placeholder="What this connector exposes"
                        style={{ ...connectorInputStyle(), resize: 'vertical' }}
                    />
                    {fieldErrors?.description && (
                        <span
                            data-testid="api-connector-form-description-error"
                            role="alert"
                            style={errorTextStyle()}
                        >
                            {fieldErrors.description}
                        </span>
                    )}
                </label>

                <label htmlFor="api-connector-form-base_url" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Base URL</span>
                    <input
                        id="api-connector-form-base_url"
                        data-testid="api-connector-form-base_url"
                        type="url"
                        value={baseUrl}
                        onChange={(e) => setBaseUrl(e.target.value)}
                        placeholder="https://api.example.com"
                        style={connectorInputStyle()}
                    />
                    {fieldErrors?.base_url && (
                        <span
                            data-testid="api-connector-form-base_url-error"
                            role="alert"
                            style={errorTextStyle()}
                        >
                            {fieldErrors.base_url}
                        </span>
                    )}
                </label>

                <label htmlFor="api-connector-form-project_key" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>KB project binding</span>
                    <select
                        id="api-connector-form-project_key"
                        data-testid="api-connector-form-project_key"
                        value={projectKey}
                        onChange={(e) => setProjectKey(e.target.value)}
                        style={connectorInputStyle()}
                    >
                        <option value="">Global (tenant default)</option>
                        {projects.map((p) => (
                            <option key={p.project_key} value={p.project_key}>
                                {p.name} ({p.project_key})
                            </option>
                        ))}
                    </select>
                    {fieldErrors?.project_key && (
                        <span
                            data-testid="api-connector-form-project_key-error"
                            role="alert"
                            style={errorTextStyle()}
                        >
                            {fieldErrors.project_key}
                        </span>
                    )}
                </label>

                <label htmlFor="api-connector-form-cloudflare-token" style={fieldLabelStyle()}>
                    <span id="api-connector-form-cloudflare-label" style={fieldCaptionStyle()}>Cloudflare rule token (optional)</span>
                    <input
                        id="api-connector-form-cloudflare-token"
                        data-testid="api-connector-form-cloudflare-token"
                        name="cloudflare_rule_token"
                        type="password"
                        autoComplete="new-password"
                        spellCheck={false}
                        value={cloudflareToken}
                        onChange={(e) => setCloudflareToken(e.target.value)}
                        aria-labelledby="api-connector-form-cloudflare-label"
                        aria-describedby={`api-connector-form-cloudflare-hint${cloudflareHeaderError ? ' api-connector-form-cloudflare-error' : ''}`}
                        aria-invalid={!!cloudflareHeaderError}
                        placeholder="Value used in your Cloudflare rule"
                        style={connectorInputStyle()}
                    />
                    <span id="api-connector-form-cloudflare-hint" style={{ ...fieldCaptionStyle(), lineHeight: 1.5, overflowWrap: 'anywhere' }}>
                        Sends <code>{CLOUDFLARE_HEADER}</code> on every endpoint test and tool call.
                        Match this header and value in a Cloudflare Skip rule for your API.
                        Leave blank to remove it. For a different header name, use Default headers below.
                    </span>
                    {cloudflareHeaderError && (
                        <span id="api-connector-form-cloudflare-error" role="alert" style={errorTextStyle()}>{cloudflareHeaderError}</span>
                    )}
                </label>

                <label htmlFor="api-connector-form-headers" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>
                        Default headers (one per line, <code>Key: Value</code>)
                    </span>
                    <textarea
                        id="api-connector-form-headers"
                        data-testid="api-connector-form-headers"
                        rows={2}
                        value={headersText}
                        onChange={(e) => setHeadersText(e.target.value)}
                        placeholder={'Accept: application/json'}
                        style={{ ...connectorInputStyle(), resize: 'vertical', fontFamily: 'var(--font-mono, monospace)' }}
                    />
                    {fieldErrors?.headers && (
                        <span
                            data-testid="api-connector-form-headers-error"
                            role="alert"
                            style={errorTextStyle()}
                        >
                            {fieldErrors.headers}
                        </span>
                    )}
                </label>

                <label
                    htmlFor="api-connector-form-is_active"
                    style={{ display: 'flex', gap: 8, alignItems: 'center', cursor: 'pointer' }}
                >
                    <input
                        id="api-connector-form-is_active"
                        data-testid="api-connector-form-is_active"
                        type="checkbox"
                        checked={isActive}
                        onChange={(e) => setIsActive(e.target.checked)}
                    />
                    <span style={fieldCaptionStyle()}>Active</span>
                </label>

                {submitError && (
                    <p data-testid="api-connector-form-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}>
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', flexWrap: 'wrap', marginTop: 4 }}>
                    <Button
                        type="button"
                        data-testid="api-connector-form-cancel"
                        onClick={onClose}
                        disabled={isSubmitting}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        data-testid="api-connector-form-submit"
                        variant="primary"
                        busy={isSubmitting}
                    >
                        {isEdit ? 'Save changes' : 'Create connector'}
                    </Button>
                </div>
            </form>
        </div>
    );
}
