import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Braces, Check, Copy, X } from 'lucide-react';
import { Button } from '../../components/Button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { useTeamStore } from '../../lib/team-store';
import { useUiTranslation } from '../../lib/ui-locale';
import { chatApi, type ToolSource } from './chat.api';
import './tool-source-modal.css';

interface ToolSourceModalProps {
    source: ToolSource;
    conversationId: number;
    messageId: number;
    onClose: () => void;
}

const json = (value: unknown): string => JSON.stringify(value, null, 2) ?? '';

export function ToolSourceModal({ source, conversationId, messageId, onClose }: ToolSourceModalProps): ReactNode {
    const { t } = useUiTranslation();
    const tenant = useTeamStore((state) => state.currentTeam);
    const { data, isLoading, isError, isFetching, refetch } = useQuery({
        queryKey: ['chat-tool-source', tenant, conversationId, messageId, source.execution_id],
        queryFn: () => chatApi.fetchToolSource(conversationId, messageId, source.execution_id, tenant),
        retry: false,
        gcTime: 0,
    });
    const state = isLoading ? 'loading' : isError ? 'error' : data ? 'ready' : 'idle';
    const invocation = data ? json({ tool: data.tool, arguments: data.arguments }) : '';
    const request = data?.request;
    const requestText = request
        ? `${request.method} ${request.url}\n\n${json({ query: request.query, headers: request.headers, body: request.body })}`
        : invocation;
    const hasResponse = data?.response !== null && data?.response !== undefined;

    return (
        <Dialog open onOpenChange={(open) => { if (!open) onClose(); }}>
            <DialogContent data-testid="chat-tool-source-modal" showCloseButton={false}
                aria-busy={isFetching} className="tool-source-dialog">
                <DialogHeader className="tool-source-header">
                    <span className="tool-source-icon"><Braces size={26} aria-hidden /></span>
                    <div className="tool-source-heading">
                        <span className="tool-source-eyebrow">{source.kind === 'mcp' ? 'MCP' : 'API'} · {t(data?.used_in_answer === false ? 'Called during research' : 'Answer source')}</span>
                        <DialogTitle>{source.display_name ?? source.tool}</DialogTitle>
                        <DialogDescription>{t('The recorded call and the result returned to the assistant.')}</DialogDescription>
                    </div>
                    <DialogClose asChild>
                        <Button variant="quiet" size="sm" iconOnly aria-label={t('Close API call')} title={t('Close API call')}>
                            <X size={16} aria-hidden />
                        </Button>
                    </DialogClose>
                </DialogHeader>
                <div className="tool-source-body" data-testid="chat-tool-source-body" data-state={state}>
                    {isLoading && <p role="status">{t('Loading API call…')}</p>}
                    {isError && <div role="alert" className="tool-source-error">
                        <p>{t('Could not load this call. It may no longer be available to you.')}</p>
                        <Button variant="secondary" size="sm" busy={isFetching} onClick={() => void refetch()}>{t('Retry')}</Button>
                    </div>}
                    {data && !isError && <>
                        <section className="tool-source-pane" aria-label={t('Request')}>
                            <div className="tool-source-section-heading">
                                <h3>{t(request ? 'Initial API request' : 'Tool call')}</h3>
                                <CopyPayload text={requestText} label={t('Copy request')} />
                            </div>
                            {request && <p className="tool-source-http"><strong>{request.method}</strong> <span>{request.url}</span></p>}
                            <pre data-testid="chat-tool-source-request" tabIndex={0}><code>{request
                                ? json({ query: request.query, headers: request.headers, body: request.body }) : invocation}</code></pre>
                            {request && <details className="tool-source-invocation">
                                <summary>{t('Tool arguments')}</summary>
                                <pre tabIndex={0}><code>{invocation}</code></pre>
                            </details>}
                            {!request && <p className="tool-source-note">{t('An HTTP request snapshot is not available for this call. These are the recorded tool arguments.')}</p>}
                        </section>
                        <section className="tool-source-pane" aria-label={t('Response')}>
                            <div className="tool-source-section-heading">
                                <h3>{t('Recorded response')}</h3>
                                {hasResponse && <CopyPayload text={json(data.response)} label={t('Copy response')} />}
                            </div>
                            {hasResponse
                                ? <pre data-testid="chat-tool-source-response" tabIndex={0}><code>{json(data.response)}</code></pre>
                                : <p>{t('No response was recorded for this call.')}</p>}
                            {data.complete === false && <p role="status" className="tool-source-note">{t('This result is partial.')}</p>}
                        </section>
                    </>}
                </div>
                <footer className="tool-source-footer">
                    <p>{t('Credentials are omitted. Responses reflect the endpoint’s configured filters and limits.')}</p>
                    {data && <div className="tool-source-meta">
                        <span>{t('Status')}: {data.status}</span>
                        {data.retrieved_at && <time dateTime={data.retrieved_at}>{new Date(data.retrieved_at).toLocaleString()}</time>}
                        {data.latency_ms != null && <span>{data.latency_ms} ms</span>}
                        <span>{t('HTTP requests')}: {data.physical_requests}</span>
                    </div>}
                </footer>
            </DialogContent>
        </Dialog>
    );
}

function CopyPayload({ text, label }: { text: string; label: string }): ReactNode {
    const { t } = useUiTranslation();
    const [copied, setCopied] = useState(false);
    const [failed, setFailed] = useState(false);
    return <div className="tool-source-copy">
        <Button variant="quiet" size="sm" aria-label={label} title={label}
            leadingIcon={copied ? <Check size={14} aria-hidden /> : <Copy size={14} aria-hidden />} onClick={async () => {
            try {
                await navigator.clipboard.writeText(text);
                setCopied(true);
                setFailed(false);
            } catch {
                setFailed(true);
            }
        }}>
            {copied ? t('Copied') : t('Copy')}
        </Button>
        {failed && <span role="status">{t('Could not copy. Select the text to copy it manually.')}</span>}
    </div>;
}
