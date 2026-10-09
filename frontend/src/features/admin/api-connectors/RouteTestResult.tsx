import { CheckCircle2, ShieldAlert, TriangleAlert } from 'lucide-react';
import type { TestResult } from './api-connectors.api';
import { prettyJson } from './pretty-json';

export interface RouteTestOutcome {
    test: TestResult;
    endpoint_type?: string;
    item_count?: number | null;
}

function header(test: TestResult, name: string): string | undefined {
    return Object.entries(test.headers ?? {}).find(([key]) => key.toLowerCase() === name)?.[1];
}

export function RouteTestResult({ result }: { result: RouteTestOutcome }) {
    const { test } = result;
    const body = typeof test.body === 'string' ? test.body : prettyJson(test.body);
    // A Cloudflare proxy header alone does not establish the cause of a 403.
    const challenge = !test.ok && !test.is_json && (
        header(test, 'cf-mitigated')?.toLowerCase() === 'challenge' ||
        /cf-chl-|\/cdn-cgi\/challenge-platform\/|challenges\.cloudflare\.com|Attention Required!.*Cloudflare/is.test(body ?? '')
    );
    const Icon = test.ok ? CheckCircle2 : challenge ? ShieldAlert : TriangleAlert;
    const title = test.ok ? 'Chiamata riuscita' : challenge ? 'Cloudflare ha bloccato la chiamata' :
        test.status === 401 ? 'Autenticazione rifiutata' :
            test.status === 403 ? 'Accesso negato' :
                test.status == null ? 'Connessione non riuscita' :
                    test.status >= 200 && test.status < 300 && !test.is_json ? 'La risposta non è JSON' : 'Chiamata non riuscita';
    const explanation = challenge
        ? "Il servizio remoto ha restituito una pagina HTML di verifica invece dei dati dell’API. Chi gestisce Cloudflare deve verificare la regola che blocca le richieste dal server AskMyDocs e consentire l’accesso autorizzato alle rotte API. Questo risultato non permette di verificare le credenziali."
        : test.ok ? 'L’endpoint ha restituito dati JSON validi.'
            : test.status === 401 ? 'Verifica il profilo di autenticazione e le credenziali richieste dall’endpoint.'
                : test.status === 403 ? 'L’endpoint ha rifiutato la richiesta. Verifica i permessi del profilo e le regole di accesso dell’API.'
                    : test.status != null && test.status >= 200 && test.status < 300 && !test.is_json ? 'L’endpoint ha risposto, ma il connettore richiede dati JSON. Controlla URL e formato della risposta.'
                        : test.error ?? 'Controlla l’endpoint e ripeti il test.';
    const ray = header(test, 'cf-ray');
    const contentType = header(test, 'content-type');

    return (
        <div className="route-test-outcome" data-testid="api-route-form-test-result" data-ok={test.ok} role={test.ok ? 'status' : 'alert'}>
            <div className="route-test-verdict" data-ok={test.ok}>
                <Icon size={20} aria-hidden="true" />
                <div>
                    <div className="route-test-verdict-title">
                        <strong>{title}</strong>
                        <span className="route-editor-badge">{test.status == null ? 'Errore di rete' : `HTTP ${test.status}`}</span>
                    </div>
                    <p>{explanation}</p>
                </div>
            </div>
            <div className="route-test-metadata">
                {test.ok && result.endpoint_type && result.endpoint_type !== 'unknown' && (
                    <span data-testid="api-route-form-test-endpoint-type" data-endpoint-type={result.endpoint_type}>
                        {result.endpoint_type === 'list' ? 'Lista' : result.endpoint_type === 'detail' ? 'Dettaglio' : result.endpoint_type}
                    </span>
                )}
                {test.ok && result.item_count != null && <span>{result.item_count} elementi</span>}
                {contentType && <span>{contentType}</span>}
                {ray && <span>Cloudflare Ray ID: <code>{ray}</code></span>}
            </div>
            <details className="route-editor-disclosure" open={test.ok}>
                <summary>Corpo della risposta <span>{test.is_json ? 'JSON' : 'Testo / HTML'}</span></summary>
                <pre className="amd-scroll route-test-body" data-testid="api-route-form-response">{body ?? 'Nessun contenuto.'}</pre>
            </details>
        </div>
    );
}
