import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { FileCheck } from 'lucide-react';
import { Button } from '../../components/Button';
import { api, ensureCsrfCookie } from '../../lib/api';
import { useUiTranslation } from '../../lib/ui-locale';
import { AuthLayout } from '../auth/AuthLayout';
import { LoginPage } from '../auth/LoginPage';

export type ImportReview = {
    import_id: string; tenant_id: string; project_key: string; status: string; ready: boolean;
    documents: { filename: string; bytes: number; sha256: string; preview: string | null }[];
    items: { filename: string; status: string; error: string | null; document_id: number | null }[];
};

export function McpImportReviewPage({ importId }: { importId: string }) {
    const { t } = useUiTranslation();
    const [review, setReview] = useState<ImportReview>();
    const [needsLogin, setNeedsLogin] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const load = useCallback(async () => {
        setError('');
        try { setReview((await api.get<ImportReview>(`/api/oauth/mcp-imports/${encodeURIComponent(importId)}`)).data); }
        catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 401) setNeedsLogin(true);
            else setError('This import is unavailable, expired, or you no longer have permission to access it.');
        }
    }, [importId]);
    useEffect(() => { void load(); }, [load]);
    async function confirm() {
        setBusy(true); setError('');
        try {
            await ensureCsrfCookie();
            setReview((await api.post<ImportReview>(`/api/oauth/mcp-imports/${encodeURIComponent(importId)}/confirm`)).data);
        } catch { setError(t('Unable to approve this import. Your permissions or the request may have changed.')); }
        finally { setBusy(false); }
    }
    if (needsLogin) return <LoginPage onSuccess={() => { setNeedsLogin(false); void load(); }}
        onNavigateForgot={() => window.location.assign('/forgot-password')} onNavigateRegister={() => window.location.assign('/register')} />;
    return <AuthLayout title={t('Review document import')} subtitle={t('Check the files and destination before adding them to your knowledge base.')}
        footer={<a href="/oauth/connections">{t('Manage connected applications')}</a>}>
        {error && <p role="alert">{t(error)}</p>}
        {!review && !error && <p role="status">{t('Loading import…')}</p>}
        {review && <>
            <p><strong>{review.tenant_id} / {review.project_key}</strong></p>
            <p>{t('These documents will be added to this project. Existing source files are preserved. The indexed content can be removed later in AskMyDocs.')}</p>
            <ul style={{ paddingLeft: 20 }}>
                {review.documents.map((doc) => <li key={doc.filename} style={{ marginBottom: 14, overflowWrap: 'anywhere' }}>
                    <strong>{doc.filename}</strong> · {doc.bytes.toLocaleString()} {t('bytes')}
                    {doc.preview && <pre style={{ whiteSpace: 'pre-wrap', maxHeight: 160, overflow: 'auto', fontSize: 12 }}>{doc.preview}</pre>}
                </li>)}
            </ul>
            <p role="status">{t(review.status === 'awaiting_approval' ? 'Waiting for your approval' : review.ready ? 'Documents are ready to search. Return to Codex.' : 'Import status:')}{!review.ready && review.status !== 'awaiting_approval' ? ` ${review.status}` : ''}</p>
            {review.items.some((item) => item.error) && <p role="alert">{t('Some files failed. Check their status in AskMyDocs before retrying.')}</p>}
            {review.status === 'awaiting_approval' && <Button variant="primary" leadingIcon={<FileCheck size={16} />} busy={busy} onClick={() => void confirm()}>{t('Approve and index documents')}</Button>}
            {review.status !== 'awaiting_approval' && !review.ready && <Button variant="secondary" onClick={() => void load()}>{t('Refresh status')}</Button>}
        </>}
    </AuthLayout>;
}
