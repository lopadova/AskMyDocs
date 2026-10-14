import { useRef, useState } from 'react';
import { Download } from 'lucide-react';
import { Button } from '../../../components/Button';
import { apiConnectorsApi, type ApiConnector } from './api-connectors.api';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';
import './config-transfer.css';

export function ConnectorExportButton({ connector, testId }: { connector: ApiConnector; testId: string }) {
    const toast = useToast();
    const [busy, setBusy] = useState(false);
    const inFlight = useRef(false);

    async function exportConfig() {
        if (inFlight.current) return;
        inFlight.current = true;
        setBusy(true);
        try {
            const config = await apiConnectorsApi.exportConfig(connector.id);
            const url = URL.createObjectURL(new Blob([JSON.stringify(config, null, 2)], { type: 'application/json' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = `${connector.name.replace(/[^a-zA-Z0-9_-]+/g, '-').slice(0, 80) || 'api'}.askmydocs-api-connector.json`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast.success('Complete API connector configuration exported.', 'toast-api-connector-exported');
        } catch (cause) {
            toast.error(toAdminError(cause).message, 'toast-api-connector-export-error');
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }

    return <Button data-testid={testId} onClick={exportConfig} busy={busy} leadingIcon={<Download size={16} />}>
        Export configuration
    </Button>;
}
