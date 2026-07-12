import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import type { ApiConnector, ApiRoute, ApplyAiConfigureResponse, TestRouteResponse } from './api-connectors.api';

type MutationStub = {
    mutate: ReturnType<typeof vi.fn>;
    isPending: boolean;
    isError: boolean;
    error: unknown;
    data: unknown;
};

const createMutate = vi.fn();
const updateMutate = vi.fn();
const testMutate = vi.fn();
const analyzeMutate = vi.fn();
const applyAiMutate = vi.fn();
const detectMutate = vi.fn();
let createState: MutationStub;
let updateState: MutationStub;
let testState: MutationStub;
let analyzeState: MutationStub;
let applyAiState: MutationStub;
let detectState: MutationStub;

vi.mock('./api-connectors-hooks', () => ({
    useCreateRoute: () => createState,
    useUpdateRoute: () => updateState,
    useTestRoute: () => testState,
    useAnalyzeRoute: () => analyzeState,
    useApplyAiConfigure: () => applyAiState,
    useDetectPagination: () => detectState,
}));
vi.mock('../shared/Toast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));

// Imported AFTER the mocks are declared.
const { RouteWorkspace } = await import('./RouteWorkspace');

const connector = {
    id: 3,
    name: 'Acme',
    base_url: 'https://api.acme.com',
    auth_profiles: [],
} as unknown as ApiConnector;

const route = {
    id: 5,
    name: 'Catalogo',
    http_method: 'GET',
    url: 'https://api.acme.com/catalog',
    parameters: [],
} as unknown as ApiRoute;

function testResult(over: Partial<TestRouteResponse['test']> = {}): TestRouteResponse {
    return {
        test: { ok: true, status: 200, status_label: 'ok', is_json: true, error: null, headers: {}, body: [{ id: 1 }], ...over },
        tool_definition: null,
        input_schema: null,
        output_schema: null,
        endpoint_type: 'list',
        items_path: 'data',
        item_schema: null,
    };
}

function stub(mutate: ReturnType<typeof vi.fn>): MutationStub {
    return { mutate, isPending: false, isError: false, error: null, data: null };
}

describe('RouteWorkspace', () => {
    beforeEach(() => {
        [createMutate, updateMutate, testMutate, analyzeMutate, applyAiMutate, detectMutate].forEach((m) => m.mockClear());
        createState = stub(createMutate);
        updateState = stub(updateMutate);
        testState = stub(testMutate);
        analyzeState = stub(analyzeMutate);
        applyAiState = stub(applyAiMutate);
        detectState = stub(detectMutate);
    });

    it('create mode: no test console (route unsaved) and submit calls createRoute', () => {
        render(<RouteWorkspace connector={connector} route={null} onClose={vi.fn()} onSaved={vi.fn()} />);

        // Right console is gated until the route exists.
        expect(screen.queryByTestId('api-route-wb-test-run')).not.toBeInTheDocument();
        expect(screen.getByTestId('api-route-form')).toHaveAttribute('aria-label', 'New route');

        fireEvent.change(screen.getByTestId('api-route-form-name'), { target: { value: 'Prodotti' } });
        fireEvent.change(screen.getByTestId('api-route-form-url'), { target: { value: '/products' } });
        fireEvent.click(screen.getByTestId('api-route-form-submit'));

        expect(createMutate).toHaveBeenCalledTimes(1);
        const [vars] = createMutate.mock.calls[0];
        expect(vars.connectorId).toBe(3);
        expect(vars.payload.name).toBe('Prodotti');
        // base + path recombine into the full URL.
        expect(vars.payload.url).toBe('https://api.acme.com/products');
    });

    it('edit mode: fires the test with parsed args and renders the result', () => {
        testState.data = testResult();
        render(<RouteWorkspace connector={connector} route={route} onClose={vi.fn()} onSaved={vi.fn()} />);

        fireEvent.change(screen.getByTestId('api-route-wb-example-args'), { target: { value: '{"q":"x"}' } });
        fireEvent.click(screen.getByTestId('api-route-wb-test-run'));
        expect(testMutate).toHaveBeenCalledWith({ routeId: 5, exampleArgs: { q: 'x' } });

        const result = screen.getByTestId('api-route-wb-test-result');
        expect(result).toHaveAttribute('data-ok', 'true');
        expect(result).toHaveTextContent('HTTP 200');
        expect(screen.getByTestId('api-route-wb-test-endpoint-type')).toHaveAttribute('data-endpoint-type', 'list');
    });

    it('blocks a run on invalid example-args JSON (no mutate)', () => {
        render(<RouteWorkspace connector={connector} route={route} onClose={vi.fn()} onSaved={vi.fn()} />);

        fireEvent.change(screen.getByTestId('api-route-wb-example-args'), { target: { value: '{ not json' } });
        fireEvent.click(screen.getByTestId('api-route-wb-test-run'));

        expect(screen.getByTestId('api-route-wb-example-args-error')).toBeInTheDocument();
        expect(testMutate).not.toHaveBeenCalled();
    });

    it('"Configura con AI" applies the suggestion to the left config and marks it dirty', () => {
        const applied: ApplyAiConfigureResponse = {
            applied: {
                endpoint_type: 'list',
                items_path: 'data.items',
                pagination: { type: 'cursor', next_cursor_path: 'meta.next' },
                tool_name: 'list_catalog',
                tool_description: 'Elenca il catalogo.',
                parameters: [{ name: 'q', location: 'query', source: 'llm', type: 'string', required: false }],
            },
            final_test: testResult().test,
            pagination_test: { pages: [], distinct: true, note: 'ok' },
            source: 'openapi',
        };
        // The mutation resolves by invoking the onSuccess callback the component passes.
        applyAiMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.(applied));
        applyAiState.data = applied;

        render(<RouteWorkspace connector={connector} route={route} onClose={vi.fn()} onSaved={vi.fn()} />);

        fireEvent.change(screen.getByTestId('api-route-wb-openapi-url'), {
            target: { value: 'https://api.acme.com/openapi.json' },
        });
        fireEvent.click(screen.getByTestId('api-route-wb-ai-configure-run'));

        expect(applyAiMutate).toHaveBeenCalledWith(
            { routeId: 5, exampleArgs: {}, openApiUrl: 'https://api.acme.com/openapi.json' },
            expect.anything(),
        );

        // Left config now reflects the applied suggestion.
        expect(screen.getByTestId('api-route-form-endpoint_type-list')).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByTestId('api-route-form-items_path')).toHaveValue('data.items');
        expect(screen.getByTestId('api-route-form-description')).toHaveValue('Elenca il catalogo.');
        expect(screen.getByTestId('api-route-form-param-0-name')).toHaveValue('q');

        // Applied strip + source badge.
        expect(screen.getByTestId('api-route-wb-ai-source')).toHaveTextContent('da OpenAPI');
        expect(screen.getByTestId('api-route-wb-ai-final-test')).toHaveTextContent('OK');

        // Dirty status reflects the unsaved applied config.
        expect(screen.getByTestId('api-route-workspace-status')).toHaveTextContent('Modifiche non salvate');
    });

    it('"Rileva" fills the pagination card deterministically (no AI)', () => {
        const detected = { config: { type: 'cursor' as const, cursor_param: 'cursor', next_cursor_path: 'meta.next_cursor' }, source: 'heuristic' as const };
        detectMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.(detected));
        detectState.data = detected; // React Query would expose this after success.
        render(<RouteWorkspace connector={connector} route={route} onClose={vi.fn()} onSaved={vi.fn()} />);

        fireEvent.click(screen.getByTestId('api-route-form-pagination-detect'));

        expect(detectMutate).toHaveBeenCalledWith({ routeId: 5, exampleArgs: {} }, expect.anything());
        expect(screen.getByTestId('api-route-form-pagination-type')).toHaveValue('cursor');
        expect(screen.getByTestId('api-route-form-pagination-next_cursor_path')).toHaveValue('meta.next_cursor');
        expect(screen.getByTestId('api-route-form-pagination-source')).toHaveTextContent('euristica');
    });
});
