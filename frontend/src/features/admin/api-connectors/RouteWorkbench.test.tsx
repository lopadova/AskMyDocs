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
const detectMutate = vi.fn();
const testPaginationMutate = vi.fn();
const updateMutate = vi.fn();
const searchMutate = vi.fn();
let testState: MutationStub;
let analyzeState: MutationStub;
let detectState: MutationStub;
let pageTestState: MutationStub;
let updateState: MutationStub;
let searchState: MutationStub;

vi.mock('./api-connectors-hooks', () => ({
    useTestRoute: () => testState,
    useAnalyzeRoute: () => analyzeState,
    useDetectPagination: () => detectState,
    useTestPagination: () => pageTestState,
    useUpdateRoute: () => updateState,
    useTestSearch: () => searchState,
}));
vi.mock('../shared/Toast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));

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
        detectMutate.mockClear();
        testPaginationMutate.mockClear();
        updateMutate.mockClear();
        testState = { mutate: testMutate, isPending: false, isError: false, error: null, data: null };
        analyzeState = { mutate: analyzeMutate, isPending: false, isError: false, error: null, data: null };
        detectState = { mutate: detectMutate, isPending: false, isError: false, error: null, data: null };
        pageTestState = { mutate: testPaginationMutate, isPending: false, isError: false, error: null, data: null };
        updateState = { mutate: updateMutate, isPending: false, isError: false, error: null, data: null };
        searchState = { mutate: searchMutate, isPending: false, isError: false, error: null, data: null };
        searchMutate.mockClear();
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

    it('shows the AI narration in the Analisi tab, or an empty hint without it', () => {
        analyzeState.data = {
            test: testResult().test,
            reduced: { data: [{ id: 1 }] },
            notes: [],
            analysis: 'A list of products under `data`, each with id + name.',
        } satisfies AnalyzeResponse;
        const { rerender } = render(<RouteWorkbench route={route} onClose={vi.fn()} />);

        fireEvent.click(screen.getByTestId('api-route-wb-tab-analysis'));
        fireEvent.click(screen.getByTestId('api-route-wb-analyze-ai-run'));
        expect(analyzeMutate).toHaveBeenCalledWith({ routeId: 5, exampleArgs: {} });
        expect(screen.getByTestId('api-route-wb-analysis')).toHaveTextContent('under `data`');

        // No AI narration (provider off) → the empty hint, not the prose block.
        analyzeState = { mutate: analyzeMutate, isPending: false, isError: false, error: null, data: { test: testResult().test, reduced: {}, notes: [], analysis: null } };
        rerender(<RouteWorkbench route={route} onClose={vi.fn()} />);
        fireEvent.click(screen.getByTestId('api-route-wb-tab-analysis'));
        expect(screen.queryByTestId('api-route-wb-analysis')).not.toBeInTheDocument();
        expect(screen.getByTestId('api-route-wb-analysis-empty')).toBeInTheDocument();
    });

    it('prefills, saves and tests the pagination config', () => {
        const pagedRoute = { ...route, pagination: { type: 'page', page_param: 'page' } } as ApiRoute;
        pageTestState.data = {
            pages: [
                { ok: true, status: 200, item_count: 2 },
                { ok: true, status: 200, item_count: 2 },
            ],
            distinct: true,
            note: 'Le due pagine restituiscono item diversi.',
        };
        render(<RouteWorkbench route={pagedRoute} onClose={vi.fn()} />);

        fireEvent.click(screen.getByTestId('api-route-wb-tab-pagination'));
        // Prefilled from route.pagination.
        expect(screen.getByTestId('api-route-wb-pagination-type')).toHaveValue('page');
        expect(screen.getByTestId('api-route-wb-pagination-page_param')).toHaveValue('page');

        fireEvent.click(screen.getByTestId('api-route-wb-pagination-detect'));
        expect(detectMutate).toHaveBeenCalled();

        fireEvent.click(screen.getByTestId('api-route-wb-pagination-save'));
        expect(updateMutate).toHaveBeenCalledWith(
            { routeId: 5, payload: { pagination: { type: 'page', page_param: 'page' } } },
            expect.anything(),
        );

        fireEvent.click(screen.getByTestId('api-route-wb-pagination-test'));
        expect(testPaginationMutate).toHaveBeenCalled();
        expect(screen.getByTestId('api-route-wb-pagination-verdict')).toHaveTextContent('distinte');
    });

    it('renders an input per llm param in the Cerca tab and fires the search', () => {
        const searchRoute = {
            ...route,
            parameters: [
                { id: 1, name: 'q', location: 'query', source: 'llm', type: 'string', required: true, value: null, secret_ref: null, description: null, sort_order: 0 },
                { id: 2, name: 'api_key', location: 'query', source: 'secret', type: 'string', required: false, value: null, secret_ref: 'key', description: null, sort_order: 1 },
            ],
        } as ApiRoute;
        searchState.data = { test: testResult().test };
        render(<RouteWorkbench route={searchRoute} onClose={vi.fn()} />);

        fireEvent.click(screen.getByTestId('api-route-wb-tab-search'));
        // Only the llm param gets an input; the secret one does not.
        expect(screen.getByTestId('api-route-wb-search-q')).toBeInTheDocument();
        expect(screen.queryByTestId('api-route-wb-search-api_key')).not.toBeInTheDocument();

        fireEvent.change(screen.getByTestId('api-route-wb-search-q'), { target: { value: 'shoes' } });
        fireEvent.click(screen.getByTestId('api-route-wb-search-run'));
        expect(searchMutate).toHaveBeenCalledWith({ routeId: 5, searchArgs: { q: 'shoes' } });
        expect(screen.getByTestId('api-route-wb-search-result')).toHaveAttribute('data-ok', 'true');
    });
});
