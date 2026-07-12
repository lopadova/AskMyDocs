import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import type { ApiRoute, AnalyzeResponse, TestRouteResponse } from './api-connectors.api';

type MutationStub = {
    mutate: ReturnType<typeof vi.fn>;
    isPending: boolean;
    isError: boolean;
    error: unknown;
    data: unknown;
};

const testMutate = vi.fn();
const analyzeMutate = vi.fn();
let testState: MutationStub;
let analyzeState: MutationStub;

vi.mock('./api-connectors-hooks', () => ({
    useTestRoute: () => testState,
    useAnalyzeRoute: () => analyzeState,
}));

// Imported AFTER the mock is declared.
const { RouteWorkbench } = await import('./RouteWorkbench');

const route = { id: 5, name: 'Catalog', http_method: 'GET', url: 'https://api.example.com/catalog' } as ApiRoute;

function testResult(over: Partial<TestRouteResponse['test']> = {}): TestRouteResponse {
    return {
        test: { ok: true, status: 200, status_label: 'ok', is_json: true, error: null, headers: {}, body: { a: 1 }, ...over },
        tool_definition: null,
        input_schema: null,
        output_schema: null,
        endpoint_type: 'list',
        items_path: 'data',
        item_schema: null,
    };
}

describe('RouteWorkbench', () => {
    beforeEach(() => {
        testMutate.mockClear();
        analyzeMutate.mockClear();
        testState = { mutate: testMutate, isPending: false, isError: false, error: null, data: null };
        analyzeState = { mutate: analyzeMutate, isPending: false, isError: false, error: null, data: null };
    });

    it('shows the Test tab by default and switches to Dati', () => {
        render(<RouteWorkbench route={route} onClose={vi.fn()} />);

        expect(screen.getByTestId('api-route-wb-panel-test')).toBeInTheDocument();
        expect(screen.queryByTestId('api-route-wb-panel-data')).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('api-route-wb-tab-data'));
        expect(screen.getByTestId('api-route-wb-panel-data')).toBeInTheDocument();
        expect(screen.getByTestId('api-route-wb-tab-data')).toHaveAttribute('aria-selected', 'true');
    });

    it('blocks a run on invalid example-args JSON (no mutate)', () => {
        render(<RouteWorkbench route={route} onClose={vi.fn()} />);

        fireEvent.change(screen.getByTestId('api-route-wb-example-args'), { target: { value: '{ not json' } });
        fireEvent.click(screen.getByTestId('api-route-wb-test-run'));

        expect(screen.getByTestId('api-route-wb-example-args-error')).toBeInTheDocument();
        expect(testMutate).not.toHaveBeenCalled();
    });

    it('fires the test with parsed args and renders the result', () => {
        testState.data = testResult();
        render(<RouteWorkbench route={route} onClose={vi.fn()} />);

        fireEvent.change(screen.getByTestId('api-route-wb-example-args'), { target: { value: '{"q":"x"}' } });
        fireEvent.click(screen.getByTestId('api-route-wb-test-run'));
        expect(testMutate).toHaveBeenCalledWith({ routeId: 5, exampleArgs: { q: 'x' } });

        const result = screen.getByTestId('api-route-wb-test-result');
        expect(result).toHaveAttribute('data-ok', 'true');
        expect(screen.getByTestId('api-route-wb-test-status')).toHaveTextContent('HTTP 200');
        expect(screen.getByTestId('api-route-wb-test-endpoint-type')).toHaveAttribute('data-endpoint-type', 'list');
    });

    it('renders the reduced structure + notes in the Dati tab', () => {
        const analyze: AnalyzeResponse = {
            test: testResult().test,
            reduced: { data: [{ id: 1 }, '… +97 more (of 100 total)'] },
            notes: [{ path: 'data', total: 100, kept: 3, omitted: 97 }],
            analysis: null,
        };
        analyzeState.data = analyze;
        render(<RouteWorkbench route={route} onClose={vi.fn()} />);

        fireEvent.click(screen.getByTestId('api-route-wb-tab-data'));
        fireEvent.click(screen.getByTestId('api-route-wb-analyze-run'));
        expect(analyzeMutate).toHaveBeenCalledWith({ routeId: 5, exampleArgs: {} });

        expect(screen.getByTestId('api-route-wb-data-notes')).toHaveTextContent('97 omessi su 100');
        expect(screen.getByTestId('api-route-wb-reduced')).toHaveTextContent('+97 more');
    });
});
