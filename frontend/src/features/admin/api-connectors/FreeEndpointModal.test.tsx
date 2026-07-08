import { describe, it, expect, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FreeEndpointModal, type FreeEndpointModalProps } from './FreeEndpointModal';
import type { ProbeResult } from './api-connectors.api';

function setup(props: Partial<FreeEndpointModalProps> = {}) {
    const onSend = vi.fn();
    const onClose = vi.fn();
    render(
        <FreeEndpointModal
            onSend={onSend}
            result={null}
            error={null}
            isSending={false}
            onClose={onClose}
            {...props}
        />,
    );
    return { onSend, onClose };
}

describe('FreeEndpointModal', () => {
    it('renders the request form; the body field is hidden for GET', () => {
        setup();
        expect(screen.getByTestId('api-probe-url')).toBeInTheDocument();
        expect(screen.getByTestId('api-probe-method')).toHaveValue('GET');
        expect(screen.getByTestId('api-probe-headers')).toBeInTheDocument();
        expect(screen.getByTestId('api-probe-query')).toBeInTheDocument();
        expect(screen.queryByTestId('api-probe-body')).not.toBeInTheDocument();
    });

    it('shows the body field for POST and hides it again for GET', async () => {
        setup();
        await userEvent.selectOptions(screen.getByTestId('api-probe-method'), 'POST');
        expect(screen.getByTestId('api-probe-body')).toBeInTheDocument();
        await userEvent.selectOptions(screen.getByTestId('api-probe-method'), 'GET');
        expect(screen.queryByTestId('api-probe-body')).not.toBeInTheDocument();
    });

    it('blocks send on an empty URL and surfaces the error', async () => {
        const { onSend } = setup();
        await userEvent.click(screen.getByTestId('api-probe-send'));
        expect(screen.getByTestId('api-probe-url-error')).toBeInTheDocument();
        expect(onSend).not.toHaveBeenCalled();
    });

    it('blocks send on an invalid JSON body and surfaces the error', async () => {
        const { onSend } = setup();
        await userEvent.selectOptions(screen.getByTestId('api-probe-method'), 'POST');
        await userEvent.type(screen.getByTestId('api-probe-url'), 'https://api.example.com/x');
        // Braces are userEvent keyboard modifiers — set the JSON via change instead.
        fireEvent.change(screen.getByTestId('api-probe-body'), { target: { value: '{ not json' } });
        await userEvent.click(screen.getByTestId('api-probe-send'));
        expect(screen.getByTestId('api-probe-body-error')).toBeInTheDocument();
        expect(onSend).not.toHaveBeenCalled();
    });

    it('sends a GET with parsed headers and query params', async () => {
        const { onSend } = setup();
        await userEvent.type(screen.getByTestId('api-probe-url'), 'https://api.example.com/orders');
        await userEvent.type(screen.getByTestId('api-probe-headers'), 'Accept: application/json');
        await userEvent.type(screen.getByTestId('api-probe-query'), 'page: 1');
        await userEvent.click(screen.getByTestId('api-probe-send'));

        expect(onSend).toHaveBeenCalledTimes(1);
        expect(onSend).toHaveBeenCalledWith({
            http_method: 'GET',
            url: 'https://api.example.com/orders',
            headers: { Accept: 'application/json' },
            query: { page: '1' },
            body: undefined,
        });
    });

    it('sends a POST with a parsed JSON body', async () => {
        const { onSend } = setup();
        await userEvent.selectOptions(screen.getByTestId('api-probe-method'), 'POST');
        await userEvent.type(screen.getByTestId('api-probe-url'), 'https://api.example.com/orders');
        fireEvent.change(screen.getByTestId('api-probe-body'), { target: { value: '{"sku":"A1"}' } });
        await userEvent.click(screen.getByTestId('api-probe-send'));

        expect(onSend).toHaveBeenCalledWith({
            http_method: 'POST',
            url: 'https://api.example.com/orders',
            headers: undefined,
            query: undefined,
            body: { sku: 'A1' },
        });
    });

    it('renders the response viewer when a result is present', () => {
        const result: ProbeResult = {
            ok: true,
            status: 200,
            status_label: 'ok',
            is_json: true,
            error: null,
            headers: {},
            body: { ok: true },
            duration_ms: 12,
        };
        setup({ result });
        expect(screen.getByTestId('api-probe-response')).toBeInTheDocument();
        expect(screen.getByTestId('api-probe-panel')).toHaveAttribute('data-state', 'ready');
    });

    it('disables the send button while a probe is in flight', () => {
        setup({ isSending: true });
        const send = screen.getByTestId('api-probe-send');
        expect(send).toBeDisabled();
        expect(send).toHaveTextContent('Sending…');
        expect(screen.getByTestId('api-probe-panel')).toHaveAttribute('data-state', 'loading');
    });

    it('fires onClose from the Close button', async () => {
        const { onClose } = setup();
        await userEvent.click(screen.getByTestId('api-probe-close'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
