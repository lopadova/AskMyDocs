import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import type { ApiAuthProfile, AuthProfilePayload, AuthType } from './api-connectors.api';
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
 * Create / edit an auth profile. The `type` select drives which credential
 * fields render. Credentials are WRITE-ONLY: the BE never echoes them
 * (`has_credentials` flag only), so on edit the fields start blank and a blank
 * submission KEEPS the existing credentials (we omit `credentials` from the
 * payload when every field is empty).
 *
 * R11/R29: testids `api-auth-profile-form-{field}`.
 * R15: bound labels, role=dialog, Esc closes, field errors next to inputs.
 */

export interface AuthProfileFormProps {
    /** Present = edit. */
    profile?: ApiAuthProfile | null;
    onSubmit: (payload: AuthProfilePayload) => void;
    onClose: () => void;
    submitError?: string | null;
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
}

const AUTH_TYPE_LABELS: Record<AuthType, string> = {
    none: 'None',
    api_key: 'API key',
    bearer: 'Bearer token',
    basic: 'Basic (user / password)',
    custom: 'Custom header',
    oauth2_cc: 'OAuth2 client credentials',
};

/** Credential field definitions per auth type — name keyed into the `credentials` object. */
const CREDENTIAL_FIELDS: Record<AuthType, { key: string; label: string; secret: boolean }[]> = {
    none: [],
    api_key: [{ key: 'api_key', label: 'API key', secret: true }],
    bearer: [{ key: 'token', label: 'Bearer token', secret: true }],
    basic: [
        { key: 'username', label: 'Username', secret: false },
        { key: 'password', label: 'Password', secret: true },
    ],
    custom: [{ key: 'value', label: 'Header value', secret: true }],
    oauth2_cc: [
        { key: 'client_id', label: 'Client ID', secret: false },
        { key: 'client_secret', label: 'Client secret', secret: true },
    ],
};

/** Config field definitions per auth type — name keyed into the `config` object. */
const CONFIG_FIELDS: Record<AuthType, { key: string; label: string; placeholder?: string }[]> = {
    none: [],
    api_key: [
        { key: 'header_name', label: 'Header name', placeholder: 'X-API-Key' },
        { key: 'query_name', label: 'Query param name (alternative)', placeholder: 'api_key' },
    ],
    bearer: [],
    basic: [],
    custom: [{ key: 'header_name', label: 'Header name', placeholder: 'X-Custom-Auth' }],
    oauth2_cc: [
        { key: 'token_url', label: 'Token URL', placeholder: 'https://auth.example.com/oauth/token' },
        { key: 'scope', label: 'Scope (optional)' },
    ],
};

export function AuthProfileForm({
    profile,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
}: AuthProfileFormProps): ReactNode {
    const isEdit = !!profile;
    const [type, setType] = useState<AuthType>(profile?.type ?? 'none');
    const [credentials, setCredentials] = useState<Record<string, string>>({});
    const [config, setConfig] = useState<Record<string, string>>(() => {
        const initial: Record<string, string> = {};
        const cfg = profile?.config ?? {};
        for (const [k, v] of Object.entries(cfg)) {
            initial[k] = typeof v === 'string' ? v : String(v ?? '');
        }
        return initial;
    });

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const credFields = useMemo(() => CREDENTIAL_FIELDS[type], [type]);
    const cfgFields = useMemo(() => CONFIG_FIELDS[type], [type]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        // Only send credentials when at least one field is filled — a blank
        // submission keeps the existing (write-only) credentials on edit.
        const filledCreds: Record<string, string> = {};
        for (const f of credFields) {
            const val = credentials[f.key];
            if (val !== undefined && val !== '') filledCreds[f.key] = val;
        }
        const filledConfig: Record<string, string> = {};
        for (const f of cfgFields) {
            const val = config[f.key];
            if (val !== undefined && val !== '') filledConfig[f.key] = val;
        }
        const payload: AuthProfilePayload = { type };
        if (Object.keys(filledCreds).length > 0) payload.credentials = filledCreds;
        if (Object.keys(filledConfig).length > 0) payload.config = filledConfig;
        onSubmit(payload);
    };

    const titleId = 'api-auth-profile-form-title';

    return (
        <div
            data-testid="api-auth-profile-form-backdrop"
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
                data-testid="api-auth-profile-form"
                data-state={isSubmitting ? 'loading' : 'idle'}
                onSubmit={handleSubmit}
                style={modalPanelStyle(440)}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    {isEdit ? 'Edit auth profile' : 'New auth profile'}
                </h2>

                <label htmlFor="api-auth-profile-form-type" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>
                        Auth type<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                    </span>
                    <select
                        id="api-auth-profile-form-type"
                        data-testid="api-auth-profile-form-type"
                        value={type}
                        onChange={(e) => {
                            setType(e.target.value as AuthType);
                            // Reset credentials when the type changes — the field set differs.
                            setCredentials({});
                        }}
                        style={inputStyle()}
                    >
                        {(Object.keys(AUTH_TYPE_LABELS) as AuthType[]).map((t) => (
                            <option key={t} value={t}>
                                {AUTH_TYPE_LABELS[t]}
                            </option>
                        ))}
                    </select>
                    {fieldErrors?.type && (
                        <span data-testid="api-auth-profile-form-type-error" role="alert" style={errorTextStyle()}>
                            {fieldErrors.type}
                        </span>
                    )}
                </label>

                {credFields.length > 0 && (
                    <fieldset
                        style={{
                            border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                            borderRadius: 8,
                            padding: 10,
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 8,
                        }}
                    >
                        <legend style={{ ...fieldCaptionStyle(), padding: '0 4px' }}>
                            Credentials{isEdit && ' (leave blank to keep existing)'}
                        </legend>
                        {credFields.map((f) => {
                            const fieldId = `api-auth-profile-form-credentials-${f.key}`;
                            return (
                                <label key={f.key} htmlFor={fieldId} style={fieldLabelStyle()}>
                                    <span style={fieldCaptionStyle()}>{f.label}</span>
                                    <input
                                        id={fieldId}
                                        data-testid={fieldId}
                                        type={f.secret ? 'password' : 'text'}
                                        autoComplete="off"
                                        value={credentials[f.key] ?? ''}
                                        onChange={(e) =>
                                            setCredentials((c) => ({ ...c, [f.key]: e.target.value }))
                                        }
                                        placeholder={isEdit && f.secret ? '•••••• (unchanged)' : ''}
                                        style={inputStyle()}
                                    />
                                </label>
                            );
                        })}
                        {isEdit && profile?.has_credentials && (
                            <p style={{ margin: 0, ...fieldCaptionStyle() }} data-testid="api-auth-profile-form-has-credentials">
                                Credentials are configured.
                            </p>
                        )}
                    </fieldset>
                )}

                {cfgFields.length > 0 && (
                    <fieldset
                        style={{
                            border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                            borderRadius: 8,
                            padding: 10,
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 8,
                        }}
                    >
                        <legend style={{ ...fieldCaptionStyle(), padding: '0 4px' }}>Configuration</legend>
                        {cfgFields.map((f) => {
                            const fieldId = `api-auth-profile-form-config-${f.key}`;
                            return (
                                <label key={f.key} htmlFor={fieldId} style={fieldLabelStyle()}>
                                    <span style={fieldCaptionStyle()}>{f.label}</span>
                                    <input
                                        id={fieldId}
                                        data-testid={fieldId}
                                        type="text"
                                        value={config[f.key] ?? ''}
                                        onChange={(e) => setConfig((c) => ({ ...c, [f.key]: e.target.value }))}
                                        placeholder={f.placeholder ?? ''}
                                        style={inputStyle()}
                                    />
                                </label>
                            );
                        })}
                    </fieldset>
                )}

                {submitError && (
                    <p data-testid="api-auth-profile-form-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}>
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="api-auth-profile-form-cancel"
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid="api-auth-profile-form-submit"
                        disabled={isSubmitting}
                        style={buttonStyle('primary', !!isSubmitting)}
                    >
                        {isSubmitting ? 'Saving…' : isEdit ? 'Save changes' : 'Create profile'}
                    </button>
                </div>
            </form>
        </div>
    );
}
