import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { Button } from '../../../components/Button';
import { connectorActionsApi, type ConnectorActionDescriptor, type ConnectorInstallationDto } from './connectors.api';
import { toAdminError } from '../shared/errors';

export function ConnectorInstallationActions({ account }: { account: ConnectorInstallationDto }) {
    return <>{(account.actions ?? []).map((action) => <InstallationAction key={action.key} account={account} action={action} />)}</>;
}

function InstallationAction({ account, action }: { account: ConnectorInstallationDto; action: ConnectorActionDescriptor }) {
    const client = useQueryClient();
    const queryKey = ['connector-installation-action', account.id, action.key];
    const status = useQuery({
        queryKey,
        queryFn: () => connectorActionsApi.status(account.id, action.key),
        enabled: action.enabled,
        refetchInterval: (query) => ['queued', 'running'].includes(query.state.data?.status ?? '') ? 3000 : false,
    });
    const start = useMutation({
        mutationFn: () => connectorActionsApi.start(account.id, action.key),
        onSuccess: (data) => client.setQueryData(queryKey, data),
    });
    const active = ['queued', 'running'].includes(status.data?.status ?? '');
    const error = start.error ?? status.error;
    const labels = { queued: 'In coda', running: 'Importazione in corso', paused: 'Importazione sospesa: riattiva l’ingest nelle impostazioni e premi per riprendere', completed: 'Importazione completata', failed: 'Importazione interrotta: premi per riprendere', cancelled: 'Importazione annullata' };
    return (
        <section style={{ marginTop: 20, display: 'grid', gap: 8 }} aria-label={action.label}>
            <p style={{ margin: 0, color: 'var(--fg-3)', fontSize: 13 }}>{action.description}</p>
            <div><Button variant="secondary" size="sm" leadingIcon={<Download size={15} />} busy={start.isPending || active}
                disabled={!action.enabled || status.isLoading || status.isError} onClick={() => start.mutate()} data-testid={`connector-action-${action.key}`}>{action.label}</Button></div>
            <div role="status" aria-live="polite" style={{ fontSize: 13, color: 'var(--fg-2)' }}>
                {status.data && <><span>{labels[status.data.status]}</span><span> · {status.data.counts.documents_added ?? 0} nuovi · {status.data.counts.documents_updated ?? 0} aggiornati · {status.data.counts.attachments_skipped ?? 0} allegati esclusi</span></>}
            </div>
            {(error || status.data?.error) && <p role="alert" style={{ margin: 0, color: 'var(--err)' }}>{error ? toAdminError(error).message : status.data?.error}</p>}
        </section>
    );
}
