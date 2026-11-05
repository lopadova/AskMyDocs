import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { RouteTestResult } from './RouteTestResult';
import type { TestResult } from './api-connectors.api';

const result = (error: string | null): TestResult => ({ ok: false, status: 200, status_label: 'ok_non_json',
    is_json: false, headers: { 'content-type': 'text/xml; charset=utf-8' },
    body: '<result><ack>KO</ack><error_message>Invalid request</error_message></result>', error });

describe('RouteTestResult', () => {
    it('explains the rejected GESCAT parameter despite HTTP 200 and labels the actual XML', () => {
        render(<RouteTestResult result={{ test: result('Endpoint rejected parameter [orderBy]. Use a value declared by its input schema.') }} />);
        expect(screen.getByRole('alert')).toHaveTextContent('Parametro non accettato dall’API');
        expect(screen.getByRole('alert')).toHaveTextContent('parametro orderBy');
        expect(screen.getByRole('alert')).toHaveTextContent('HTTP 200');
        expect(screen.queryByText('La risposta non è JSON')).not.toBeInTheDocument();
        expect(screen.getByText('XML')).toBeInTheDocument();
    });

    it('distinguishes an application rejection from an unknown non-JSON response', () => {
        const view = render(<RouteTestResult result={{ test: result('Endpoint returned an application error (ack=KO).') }} />);
        expect(screen.getByRole('alert')).toHaveTextContent('Errore applicativo della sorgente');
        view.rerender(<RouteTestResult result={{ test: result(null) }} />);
        expect(screen.getByRole('alert')).toHaveTextContent('La risposta non è JSON');
    });
});
