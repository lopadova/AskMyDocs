import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import type {
    ApiAuthProfile,
    ApiRoute,
    EndpointTypeChoice,
    HttpMethod,
    ParamLocation,
    ParamSource,
    ParamType,
    RouteMode,
    RouteParameterInput,
    RoutePayload,
} from './api-connectors.api';
import {
    buttonStyle,
    errorTextStyle,
    fieldCaptionStyle,
    fieldLabelStyle,
    inputStyle,
    modalBackdropStyle,
    modalPanelStyle,
} from './styles';

/**
 * Create / edit a route (Rotta). Fields: name / description / method / url, an
 * inline parameters editor (one row per param), a static auth-profile select
 * (inherit the connector default or override), and operational overrides
 * (timeout / cache TTL / rate limit).
 *
 * Mode toggle: `tool` is enabled; `ingest` / `both` are RENDERED but DISABLED
 * (Fase 2) with a tooltip — so the operator sees the future surface without
 * being able to select an unimplemented mode.
 *
 * R11/R29: testids `api-route-form-{field}` + per-row `api-route-form-param-{idx}-{field}`.
 * R15: bound labels, role=dialog, Esc closes, field errors next to inputs.
 */

const HTTP_METHODS: HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
const PARAM_LOCATIONS: ParamLocation[] = ['path', 'query', 'header', 'body'];
const PARAM_SOURCES: ParamSource[] = ['llm', 'fixed', 'secret'];
const PARAM_TYPES: ParamType[] = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

const MODE_OPTIONS: { value: RouteMode; label: string; enabled: boolean }[] = [
    { value: 'tool', label: 'Tool', enabled: true },
    { value: 'ingest', label: 'Ingest', enabled: false },
    { value: 'both', label: 'Both', enabled: false },
];

const ENDPOINT_TYPE_OPTIONS: { value: EndpointTypeChoice; label: string; hint: string }[] = [
    { value: 'auto', label: 'Auto', hint: 'Detect from the test response' },
    { value: 'list', label: 'List', hint: 'Returns a collection of items' },
    { value: 'detail', label: 'Detail', hint: 'Returns a single resource' },
];

const FASE_2_TOOLTIP = 'Available in Fase 2';

/** The form's endpoint-type choice: an explicit locked override, else 'auto'. */
function initialEndpointChoice(route: ApiRoute | null | undefined): EndpointTypeChoice {
    if (route?.endpoint_type_locked && route.endpoint_type !== 'unknown') {
        return route.endpoint_type;
    }
    return 'auto';
}

interface ParamRow extends RouteParameterInput {
    /** Stable client-side key for React list reconciliation (R17). */
    _key: string;
}

let paramKeySeq = 1;

function emptyParam(): ParamRow {
    return {
        _key: `param-${paramKeySeq++}`,
        name: '',
        location: 'query',
        source: 'llm',
        type: 'string',
        required: false,
        value: '',
        description: '',
    };
}

function toRows(params: ApiRoute['parameters']): ParamRow[] {
    if (!params || params.length === 0) return [];
    return params.map((p) => ({
        _key: `param-${paramKeySeq++}`,
        name: p.name,
        location: p.location,
        source: p.source,
        type: p.type,
        required: p.required,
        value: p.value ?? '',
        secret_ref: p.secret_ref ?? '',
        description: p.description ?? '',
        sort_order: p.sort_order ?? undefined,
    }));
}

export interface RouteFormProps {
    /** Present = edit. */
    route?: ApiRoute | null;
    authProfiles: ApiAuthProfile[];
    onSubmit: (payload: RoutePayload) => void;
    onClose: () => void;
    submitError?: string | null;
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
}

export function RouteForm({
    route,
    authProfiles,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
}: RouteFormProps): ReactNode {
    const isEdit = !!route;
    const [name, setName] = useState(route?.name ?? '');
    const [slug, setSlug] = useState(route?.slug ?? '');
    const [description, setDescription] = useState(route?.description ?? '');
    const [method, setMethod] = useState<HttpMethod>(route?.http_method ?? 'GET');
    const [url, setUrl] = useState(route?.url ?? '');
    const [authProfileId, setAuthProfileId] = useState<string>(
        route?.auth_profile_id != null ? String(route.auth_profile_id) : '',
    );
    const [mode] = useState<RouteMode>(route?.mode ?? 'tool');
    const [endpointType, setEndpointType] = useState<EndpointTypeChoice>(initialEndpointChoice(route));
    const [itemsPath, setItemsPath] = useState<string>(route?.items_path ?? '');
    const [timeoutMs, setTimeoutMs] = useState<string>(
        route?.timeout_ms != null ? String(route.timeout_ms) : '',
    );
    const [cacheTtlS, setCacheTtlS] = useState<string>(
        route?.cache_ttl_s != null ? String(route.cache_ttl_s) : '',
    );
    const [rateLimit, setRateLimit] = useState<string>(
        route?.rate_limit != null ? String(route.rate_limit) : '',
    );
    const [params, setParams] = useState<ParamRow[]>(() => toRows(route?.parameters));

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    function updateParam(key: string, patch: Partial<ParamRow>) {
        setParams((rows) => rows.map((r) => (r._key === key ? { ...r, ...patch } : r)));
    }

    function removeParam(key: string) {
        setParams((rows) => rows.filter((r) => r._key !== key));
    }

    function parseIntOrNull(raw: string): number | null {
        const trimmed = raw.trim();
        if (trimmed === '') return null;
        const n = Number.parseInt(trimmed, 10);
        return Number.isNaN(n) ? null : n;
    }

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const parameters: RouteParameterInput[] = params.map((p, idx) => ({
            name: p.name.trim(),
            location: p.location,
            source: p.source,
            type: p.type,
            required: p.required,
            value: p.value?.trim() === '' ? null : p.value,
            secret_ref: p.secret_ref?.trim() === '' ? null : p.secret_ref,
            description: p.description?.trim() === '' ? null : p.description,
            sort_order: idx,
        }));
        onSubmit({
            name: name.trim(),
            slug: slug.trim() === '' ? null : slug.trim(),
            description: description.trim() === '' ? null : description.trim(),
            http_method: method,
            url: url.trim(),
            auth_profile_id: authProfileId === '' ? null : Number.parseInt(authProfileId, 10),
            mode,
            endpoint_type: endpointType,
            // Only meaningful for an explicit list ('' = top-level array). For
            // 'auto' the detector owns items_path; for 'detail' it is cleared.
            items_path: endpointType === 'list' ? itemsPath.trim() : endpointType === 'detail' ? null : undefined,
            timeout_ms: parseIntOrNull(timeoutMs),
            cache_ttl_s: parseIntOrNull(cacheTtlS),
            rate_limit: parseIntOrNull(rateLimit),
            parameters,
        });
    };

    const titleId = 'api-route-form-title';

    return (
        <div
            data-testid="api-route-form-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={modalBackdropStyle()}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isSubmitting}
                data-testid="api-route-form"
                data-state={isSubmitting ? 'loading' : 'idle'}
                onSubmit={handleSubmit}
                style={modalPanelStyle(680)}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    {isEdit ? `Edit route: ${route?.name}` : 'New route'}
                </h2>

                <label htmlFor="api-route-form-name" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>
                        Name<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                    </span>
                    <input
                        id="api-route-form-name"
                        data-testid="api-route-form-name"
                        type="text"
                        required
                        pattern={'.*\\S.*'}
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="e.g. Get current weather"
                        style={inputStyle()}
                    />
                    {fieldErrors?.name && (
                        <span data-testid="api-route-form-name-error" role="alert" style={errorTextStyle()}>
                            {fieldErrors.name}
                        </span>
                    )}
                </label>

                <label htmlFor="api-route-form-slug" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Slug (optional — derived from name)</span>
                    <input
                        id="api-route-form-slug"
                        data-testid="api-route-form-slug"
                        type="text"
                        value={slug}
                        onChange={(e) => setSlug(e.target.value)}
                        placeholder="get-current-weather"
                        style={inputStyle()}
                    />
                    {fieldErrors?.slug && (
                        <span data-testid="api-route-form-slug-error" role="alert" style={errorTextStyle()}>
                            {fieldErrors.slug}
                        </span>
                    )}
                </label>

                <label htmlFor="api-route-form-description" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Description (used in the tool definition)</span>
                    <textarea
                        id="api-route-form-description"
                        data-testid="api-route-form-description"
                        rows={2}
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        style={{ ...inputStyle(), resize: 'vertical' }}
                    />
                    {fieldErrors?.description && (
                        <span data-testid="api-route-form-description-error" role="alert" style={errorTextStyle()}>
                            {fieldErrors.description}
                        </span>
                    )}
                </label>

                <div style={{ display: 'flex', gap: 10 }}>
                    <label htmlFor="api-route-form-http_method" style={{ ...fieldLabelStyle(), width: 120 }}>
                        <span style={fieldCaptionStyle()}>
                            Method<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                        </span>
                        <select
                            id="api-route-form-http_method"
                            data-testid="api-route-form-http_method"
                            value={method}
                            onChange={(e) => setMethod(e.target.value as HttpMethod)}
                            style={inputStyle()}
                        >
                            {HTTP_METHODS.map((m) => (
                                <option key={m} value={m}>
                                    {m}
                                </option>
                            ))}
                        </select>
                        {fieldErrors?.http_method && (
                            <span data-testid="api-route-form-http_method-error" role="alert" style={errorTextStyle()}>
                                {fieldErrors.http_method}
                            </span>
                        )}
                    </label>

                    <label htmlFor="api-route-form-url" style={{ ...fieldLabelStyle(), flex: 1 }}>
                        <span style={fieldCaptionStyle()}>
                            URL<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                        </span>
                        <input
                            id="api-route-form-url"
                            data-testid="api-route-form-url"
                            type="text"
                            required
                            pattern={'.*\\S.*'}
                            value={url}
                            onChange={(e) => setUrl(e.target.value)}
                            placeholder="https://api.example.com/weather/{city}"
                            style={{ ...inputStyle(), fontFamily: 'var(--font-mono, monospace)' }}
                        />
                        {fieldErrors?.url && (
                            <span data-testid="api-route-form-url-error" role="alert" style={errorTextStyle()}>
                                {fieldErrors.url}
                            </span>
                        )}
                    </label>
                </div>

                {/* Mode toggle — `tool` enabled; ingest/both disabled (Fase 2). */}
                <fieldset
                    data-testid="api-route-form-mode"
                    style={{
                        border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                        borderRadius: 8,
                        padding: '8px 10px',
                        display: 'flex',
                        gap: 14,
                        alignItems: 'center',
                    }}
                >
                    <legend style={{ ...fieldCaptionStyle(), padding: '0 4px' }}>Mode</legend>
                    {MODE_OPTIONS.map((opt) => {
                        const id = `api-route-form-mode-${opt.value}`;
                        return (
                            <label
                                key={opt.value}
                                htmlFor={id}
                                title={opt.enabled ? undefined : FASE_2_TOOLTIP}
                                style={{
                                    display: 'flex',
                                    gap: 5,
                                    alignItems: 'center',
                                    cursor: opt.enabled ? 'pointer' : 'not-allowed',
                                    opacity: opt.enabled ? 1 : 0.5,
                                }}
                            >
                                <input
                                    id={id}
                                    data-testid={id}
                                    type="radio"
                                    name="api-route-form-mode-group"
                                    value={opt.value}
                                    checked={mode === opt.value}
                                    disabled={!opt.enabled}
                                    readOnly
                                    aria-label={
                                        opt.enabled ? opt.label : `${opt.label} (${FASE_2_TOOLTIP})`
                                    }
                                />
                                <span style={fieldCaptionStyle()}>
                                    {opt.label}
                                    {!opt.enabled && ' — Fase 2'}
                                </span>
                            </label>
                        );
                    })}
                </fieldset>

                {/* Endpoint taxonomy (Lista vs Dettaglio). Auto lets the server
                    detect it from the test response; List/Detail lock an override. */}
                <fieldset
                    data-testid="api-route-form-endpoint_type"
                    style={{
                        border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                        borderRadius: 8,
                        padding: '8px 10px',
                        display: 'flex',
                        gap: 14,
                        alignItems: 'center',
                        flexWrap: 'wrap',
                    }}
                >
                    <legend style={{ ...fieldCaptionStyle(), padding: '0 4px' }}>Endpoint type</legend>
                    {ENDPOINT_TYPE_OPTIONS.map((opt) => {
                        const id = `api-route-form-endpoint_type-${opt.value}`;
                        return (
                            <label
                                key={opt.value}
                                htmlFor={id}
                                title={opt.hint}
                                style={{ display: 'flex', gap: 5, alignItems: 'center', cursor: 'pointer' }}
                            >
                                <input
                                    id={id}
                                    data-testid={id}
                                    type="radio"
                                    name="api-route-form-endpoint_type-group"
                                    value={opt.value}
                                    checked={endpointType === opt.value}
                                    onChange={() => setEndpointType(opt.value)}
                                    aria-label={`${opt.label} — ${opt.hint}`}
                                />
                                <span style={fieldCaptionStyle()}>{opt.label}</span>
                            </label>
                        );
                    })}
                    {fieldErrors?.endpoint_type && (
                        <span data-testid="api-route-form-endpoint_type-error" role="alert" style={errorTextStyle()}>
                            {fieldErrors.endpoint_type}
                        </span>
                    )}
                </fieldset>

                {endpointType === 'list' && (
                    <label htmlFor="api-route-form-items_path" style={fieldLabelStyle()}>
                        <span style={fieldCaptionStyle()}>
                            Items path (dot-path to the item array — leave blank for a top-level array)
                        </span>
                        <input
                            id="api-route-form-items_path"
                            data-testid="api-route-form-items_path"
                            type="text"
                            value={itemsPath}
                            onChange={(e) => setItemsPath(e.target.value)}
                            placeholder="e.g. data — or blank for [ … ]"
                            style={{ ...inputStyle(), fontFamily: 'var(--font-mono, monospace)' }}
                        />
                        {fieldErrors?.items_path && (
                            <span data-testid="api-route-form-items_path-error" role="alert" style={errorTextStyle()}>
                                {fieldErrors.items_path}
                            </span>
                        )}
                    </label>
                )}

                <label htmlFor="api-route-form-auth_profile_id" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Auth profile</span>
                    <select
                        id="api-route-form-auth_profile_id"
                        data-testid="api-route-form-auth_profile_id"
                        value={authProfileId}
                        onChange={(e) => setAuthProfileId(e.target.value)}
                        style={inputStyle()}
                    >
                        <option value="">Inherit connector default</option>
                        {authProfiles.map((p) => (
                            <option key={p.id} value={String(p.id)}>
                                #{p.id} — {p.type}
                                {p.has_credentials ? ' (configured)' : ''}
                            </option>
                        ))}
                    </select>
                    {fieldErrors?.auth_profile_id && (
                        <span data-testid="api-route-form-auth_profile_id-error" role="alert" style={errorTextStyle()}>
                            {fieldErrors.auth_profile_id}
                        </span>
                    )}
                </label>

                {/* Operational overrides */}
                <div style={{ display: 'flex', gap: 10 }}>
                    <label htmlFor="api-route-form-timeout_ms" style={{ ...fieldLabelStyle(), flex: 1 }}>
                        <span style={fieldCaptionStyle()}>Timeout (ms)</span>
                        <input
                            id="api-route-form-timeout_ms"
                            data-testid="api-route-form-timeout_ms"
                            type="number"
                            min={1}
                            value={timeoutMs}
                            onChange={(e) => setTimeoutMs(e.target.value)}
                            placeholder="default"
                            style={inputStyle()}
                        />
                        {fieldErrors?.timeout_ms && (
                            <span data-testid="api-route-form-timeout_ms-error" role="alert" style={errorTextStyle()}>
                                {fieldErrors.timeout_ms}
                            </span>
                        )}
                    </label>
                    <label htmlFor="api-route-form-cache_ttl_s" style={{ ...fieldLabelStyle(), flex: 1 }}>
                        <span style={fieldCaptionStyle()}>Cache TTL (s)</span>
                        <input
                            id="api-route-form-cache_ttl_s"
                            data-testid="api-route-form-cache_ttl_s"
                            type="number"
                            min={0}
                            value={cacheTtlS}
                            onChange={(e) => setCacheTtlS(e.target.value)}
                            placeholder="0"
                            style={inputStyle()}
                        />
                        {fieldErrors?.cache_ttl_s && (
                            <span data-testid="api-route-form-cache_ttl_s-error" role="alert" style={errorTextStyle()}>
                                {fieldErrors.cache_ttl_s}
                            </span>
                        )}
                    </label>
                    <label htmlFor="api-route-form-rate_limit" style={{ ...fieldLabelStyle(), flex: 1 }}>
                        <span style={fieldCaptionStyle()}>Rate limit (/min)</span>
                        <input
                            id="api-route-form-rate_limit"
                            data-testid="api-route-form-rate_limit"
                            type="number"
                            min={0}
                            value={rateLimit}
                            onChange={(e) => setRateLimit(e.target.value)}
                            placeholder="0"
                            style={inputStyle()}
                        />
                        {fieldErrors?.rate_limit && (
                            <span data-testid="api-route-form-rate_limit-error" role="alert" style={errorTextStyle()}>
                                {fieldErrors.rate_limit}
                            </span>
                        )}
                    </label>
                </div>

                {/* Parameters editor */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ ...fieldCaptionStyle(), fontWeight: 600 }}>Parameters</span>
                        <button
                            type="button"
                            data-testid="api-route-form-param-add"
                            onClick={() => setParams((rows) => [...rows, emptyParam()])}
                            style={buttonStyle('secondary', false)}
                        >
                            + Add parameter
                        </button>
                    </div>

                    {params.length === 0 && (
                        <p data-testid="api-route-form-params-empty" style={{ margin: 0, ...fieldCaptionStyle() }}>
                            No parameters. The route is called as-is.
                        </p>
                    )}

                    {params.map((p, idx) => {
                        const base = `api-route-form-param-${idx}`;
                        return (
                            <div
                                key={p._key}
                                data-testid={base}
                                style={{
                                    border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                                    borderRadius: 8,
                                    padding: 8,
                                    display: 'flex',
                                    flexWrap: 'wrap',
                                    gap: 8,
                                    alignItems: 'flex-end',
                                }}
                            >
                                <label htmlFor={`${base}-name`} style={{ ...fieldLabelStyle(), flex: '1 1 140px' }}>
                                    <span style={fieldCaptionStyle()}>Name</span>
                                    <input
                                        id={`${base}-name`}
                                        data-testid={`${base}-name`}
                                        type="text"
                                        value={p.name}
                                        onChange={(e) => updateParam(p._key, { name: e.target.value })}
                                        style={inputStyle()}
                                    />
                                </label>
                                <label htmlFor={`${base}-location`} style={{ ...fieldLabelStyle(), flex: '0 1 100px' }}>
                                    <span style={fieldCaptionStyle()}>Location</span>
                                    <select
                                        id={`${base}-location`}
                                        data-testid={`${base}-location`}
                                        value={p.location}
                                        onChange={(e) =>
                                            updateParam(p._key, { location: e.target.value as ParamLocation })
                                        }
                                        style={inputStyle()}
                                    >
                                        {PARAM_LOCATIONS.map((l) => (
                                            <option key={l} value={l}>
                                                {l}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label htmlFor={`${base}-source`} style={{ ...fieldLabelStyle(), flex: '0 1 90px' }}>
                                    <span style={fieldCaptionStyle()}>Source</span>
                                    <select
                                        id={`${base}-source`}
                                        data-testid={`${base}-source`}
                                        value={p.source}
                                        onChange={(e) =>
                                            updateParam(p._key, { source: e.target.value as ParamSource })
                                        }
                                        style={inputStyle()}
                                    >
                                        {PARAM_SOURCES.map((s) => (
                                            <option key={s} value={s}>
                                                {s}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label htmlFor={`${base}-type`} style={{ ...fieldLabelStyle(), flex: '0 1 100px' }}>
                                    <span style={fieldCaptionStyle()}>Type</span>
                                    <select
                                        id={`${base}-type`}
                                        data-testid={`${base}-type`}
                                        value={p.type}
                                        onChange={(e) => updateParam(p._key, { type: e.target.value as ParamType })}
                                        style={inputStyle()}
                                    >
                                        {PARAM_TYPES.map((t) => (
                                            <option key={t} value={t}>
                                                {t}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label
                                    htmlFor={`${base}-required`}
                                    style={{ display: 'flex', gap: 4, alignItems: 'center', flex: '0 0 auto', paddingBottom: 6 }}
                                >
                                    <input
                                        id={`${base}-required`}
                                        data-testid={`${base}-required`}
                                        type="checkbox"
                                        checked={!!p.required}
                                        onChange={(e) => updateParam(p._key, { required: e.target.checked })}
                                    />
                                    <span style={fieldCaptionStyle()}>Req.</span>
                                </label>
                                <label htmlFor={`${base}-value`} style={{ ...fieldLabelStyle(), flex: '1 1 120px' }}>
                                    <span style={fieldCaptionStyle()}>
                                        {p.source === 'secret' ? 'Secret ref' : 'Default value'}
                                    </span>
                                    {p.source === 'secret' ? (
                                        <input
                                            id={`${base}-value`}
                                            data-testid={`${base}-secret_ref`}
                                            type="text"
                                            value={p.secret_ref ?? ''}
                                            onChange={(e) => updateParam(p._key, { secret_ref: e.target.value })}
                                            placeholder="credential key name"
                                            style={inputStyle()}
                                        />
                                    ) : (
                                        <input
                                            id={`${base}-value`}
                                            data-testid={`${base}-value`}
                                            type="text"
                                            value={p.value ?? ''}
                                            onChange={(e) => updateParam(p._key, { value: e.target.value })}
                                            style={inputStyle()}
                                        />
                                    )}
                                </label>
                                <label htmlFor={`${base}-description`} style={{ ...fieldLabelStyle(), flex: '1 1 160px' }}>
                                    <span style={fieldCaptionStyle()}>Description</span>
                                    <input
                                        id={`${base}-description`}
                                        data-testid={`${base}-description`}
                                        type="text"
                                        value={p.description ?? ''}
                                        onChange={(e) => updateParam(p._key, { description: e.target.value })}
                                        style={inputStyle()}
                                    />
                                </label>
                                <button
                                    type="button"
                                    data-testid={`${base}-remove`}
                                    aria-label={`Remove parameter ${idx + 1}`}
                                    onClick={() => removeParam(p._key)}
                                    style={buttonStyle('danger', false)}
                                >
                                    Remove
                                </button>
                            </div>
                        );
                    })}
                    {fieldErrors?.parameters && (
                        <span data-testid="api-route-form-parameters-error" role="alert" style={errorTextStyle()}>
                            {fieldErrors.parameters}
                        </span>
                    )}
                </div>

                {submitError && (
                    <p data-testid="api-route-form-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}>
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="api-route-form-cancel"
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid="api-route-form-submit"
                        disabled={isSubmitting}
                        style={buttonStyle('primary', !!isSubmitting)}
                    >
                        {isSubmitting ? 'Saving…' : isEdit ? 'Save changes' : 'Create route'}
                    </button>
                </div>
            </form>
        </div>
    );
}
