import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ResponseViewer } from './ResponseViewer';
import type { ProbeResult } from './api-connectors.api';

function result(over: Partial<ProbeResult> = {}): ProbeResult {
    return {
        ok: true,
        status: 200,
        status_label: 'ok',
        is_json: true,
        error: null,
        headers: { 'content-type': 'application/json' },
        body: { orders: [{ id: 1 }] },
        duration_ms: 42,
        ...over,
    };
}

describe('ResponseViewer', () => {
    it('renders nothing before the first send', () => {
        const { container } = render(<ResponseViewer result={null} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('surfaces a request-level error and hides the response block', () => {
        render(<ResponseViewer result={null} error="Network unreachable" />);
        expect(screen.getByTestId('api-probe-response-error')).toHaveTextContent('Network unreachable');
        expect(screen.queryByTestId('api-probe-response')).not.toBeInTheDocument();
    });

    it('renders an OK response with status, timing, headers and a JSON body', () => {
        render(<ResponseViewer result={result()} />);

        expect(screen.getByTestId('api-probe-response')).toHaveAttribute('data-ok', 'true');
        const status = screen.getByTestId('api-probe-response-status');
        expect(status).toHaveTextContent('OK');
        expect(status).toHaveTextContent('HTTP 200');
        expect(screen.getByTestId('api-probe-response-duration')).toHaveTextContent('42 ms');
        expect(screen.getByTestId('api-probe-response-headers')).toHaveTextContent('content-type');
        const body = screen.getByTestId('api-probe-response-body');
        expect(body).toHaveAttribute('data-format', 'json');
        expect(body).toHaveTextContent('orders');
    });

    it('renders a failed upstream response with the error and status loudly', () => {
        render(
            <ResponseViewer
                result={result({
                    ok: false,
                    status: 500,
                    status_label: 'http_500',
                    error: 'Endpoint returned HTTP 500.',
                    body: { message: 'boom' },
                })}
            />,
        );

        expect(screen.getByTestId('api-probe-response')).toHaveAttribute('data-ok', 'false');
        expect(screen.getByTestId('api-probe-response-status')).toHaveTextContent('Failed');
        expect(screen.getByTestId('api-probe-response-status')).toHaveTextContent('HTTP 500');
        expect(screen.getByTestId('api-probe-response-upstream-error')).toHaveTextContent(
            'Endpoint returned HTTP 500.',
        );
    });

    it('renders a non-JSON body as text', () => {
        render(<ResponseViewer result={result({ is_json: false, body: '<html>hi</html>' })} />);

        const body = screen.getByTestId('api-probe-response-body');
        expect(body).toHaveAttribute('data-format', 'text');
        expect(body).toHaveTextContent('<html>hi</html>');
    });
});
