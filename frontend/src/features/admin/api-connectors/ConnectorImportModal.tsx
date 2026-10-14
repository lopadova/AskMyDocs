import { useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { Button } from '../../../components/Button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '../../../components/ui/dialog';
import { apiConnectorsApi, type ConnectorImportCredentials, type ConnectorImportPreview } from './api-connectors.api';
import { useImportApiConnector } from './api-connectors-hooks';
import { toAdminError } from '../shared/errors';
import { fieldCaptionStyle, fieldLabelStyle, inputStyle } from './styles';
import './config-transfer.css';

export function ConnectorImportModal({ onClose, onImported }: { onClose: () => void; onImported: () => void }) {
    const importConnector = useImportApiConnector();
    const [preview, setPreview] = useState<ConnectorImportPreview | null>(null);
    const [credentials, setCredentials] = useState<ConnectorImportCredentials>({});
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const selection = useRef(0);
    const opener = useRef(document.activeElement instanceof HTMLElement ? document.activeElement : null);
    const busy = loading || importConnector.isPending;

    async function loadFile(event: ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        if (!file) return;
        const current = ++selection.current;
        setPreview(null);
        setCredentials({});
        setError(null);
        setFieldErrors({});
        setLoading(true);
        try {
            if (file.size > 5 * 1024 * 1024) throw new Error('Choose a configuration file smaller than 5 MB.');
            let config: unknown;
            try { config = JSON.parse(await file.text()); }
            catch { throw new Error('This file does not contain valid JSON.'); }
            const result = await apiConnectorsApi.previewImport(config);
            if (selection.current === current) setPreview(result);
        } catch (cause) {
            if (selection.current === current) setError(cause instanceof Error && !('isAxiosError' in cause)
                ? cause.message : toAdminError(cause).message);
        } finally {
            if (selection.current === current) setLoading(false);
        }
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (!preview || busy) return;
        setError(null);
        setFieldErrors({});
        try {
            await importConnector.mutateAsync({ config: preview.config, credentials });
            onImported();
        } catch (cause) {
            const failure = toAdminError(cause);
            setError(failure.message);
            setFieldErrors(failure.fieldErrors);
        }
    }

    return (
        <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
            <DialogContent className="api-config-import-panel" showCloseButton={false} onCloseAutoFocus={(event) => {
                event.preventDefault();
                opener.current?.focus();
            }}>
                <form onSubmit={submit} data-testid="api-connector-import-form" aria-busy={busy} className="api-config-import-form">
                    <DialogTitle>Import API connector</DialogTitle>
                    <DialogDescription>Restore the complete configuration, including endpoints. Enter only the missing credentials.</DialogDescription>
                    <label style={fieldLabelStyle()} htmlFor="api-connector-import-file">
                        <span style={fieldCaptionStyle()}>Configuration file</span>
                        <input id="api-connector-import-file" data-testid="api-connector-import-file" type="file" accept=".json,application/json"
                            onChange={loadFile} disabled={busy} style={inputStyle()} />
                    </label>
                    {loading && <p role="status">Reading configuration…</p>}
                    {preview && <>
                        <p data-testid="api-connector-import-summary" role="status">
                            <strong>{preview.name}</strong> · {preview.endpoint_count} endpoints · {preview.relation_count} relations
                        </p>
                        {preview.required_credentials.length === 0
                            ? <p>No credentials are required. The configuration is ready to import.</p>
                            : <p>Secrets were omitted from the export. All other settings will be restored automatically.</p>}
                        {preview.required_credentials.map((field, index) => {
                            const id = `api-connector-import-credential-${index}`;
                            const failure = fieldErrors[`credentials.${field.profile_ref}.${field.key}`];
                            return <label key={`${field.profile_ref}:${field.key}`} htmlFor={id} style={fieldLabelStyle()}>
                                <span style={fieldCaptionStyle()}>{field.label} <span className="api-config-import-profile">{field.profile_label ?? 'Authentication'}</span></span>
                                <input id={id} data-testid={id} required type={field.secret ? 'password' : 'text'}
                                    autoComplete="off" spellCheck={false} disabled={busy} aria-invalid={!!failure}
                                    aria-describedby={failure ? `${id}-error` : undefined}
                                    value={credentials[field.profile_ref]?.[field.key] ?? ''} style={inputStyle()}
                                    onChange={(event) => setCredentials((previous) => ({ ...previous,
                                        [field.profile_ref]: { ...previous[field.profile_ref], [field.key]: event.target.value },
                                    }))} />
                                {failure && <span id={`${id}-error`} role="alert">{failure}</span>}
                            </label>;
                        })}
                    </>}
                    {error && <p role="alert" data-testid="api-connector-import-error">{error}</p>}
                    <div className="api-config-transfer-actions">
                        <Button onClick={onClose} disabled={busy}>Cancel</Button>
                        <Button type="submit" variant="primary" data-testid="api-connector-import-submit"
                            busy={importConnector.isPending} disabled={!preview || loading}>Import connector</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
