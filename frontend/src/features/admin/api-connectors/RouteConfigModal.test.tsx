import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import type { ApiConnector, ApiRoute, ProduceConfigResponse, RouteConfig, TestConfigResponse } from './api-connectors.api';

type MutationStub = { mutate: ReturnType<typeof vi.fn>; isPending: boolean; isError: boolean; error: unknown; data: unknown };

const createMutate = vi.fn();
const updateMutate = vi.fn();
const testRouteMutate = vi.fn();
const testMutate = vi.fn();
const produceMutate = vi.fn();
let createState: MutationStub;
let updateState: MutationStub;
let testRouteState: MutationStub;
let testState: MutationStub;
let produceState: MutationStub;

vi.mock('./api-connectors-hooks', () => ({
    useCreateRoute: () => createState,
    useUpdateRoute: () => updateState,
    useTestRoute: () => testRouteState,
    useTestConfig: () => testState,
    useProduceConfig: () => produceState,
}));
vi.mock('../shared/Toast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));

const { RouteConfigModal } = await import('./RouteConfigModal');

const connector = { id: 3, name: 'Acme', base_url: 'https://api.acme.com', default_auth_profile_id: null, auth_profiles: [] } as unknown as ApiConnector;

function config(over: Partial<RouteConfig> = {}): RouteConfig {
    return {
        identity: { name: 'Catalogo', slug: null, description: null, mode: 'tool' },
        request: { http_method: 'GET', url: 'https://api.acme.com/catalog', auth_profile_id: null, params: [] },
        response: { endpoint_type: 'auto', items_path: null, transform: null, pagination: null },
        options: { timeout_ms: null, cache_ttl_s: null, rate_limit: null },
        ...over,
    };
}

function stub(m: ReturnType<typeof vi.fn>): MutationStub {
    return { mutate: m, isPending: false, isError: false, error: null, data: null };
}

function testResult(over = {}) {
    return { ok: true, status: 200, status_label: 'ok', is_json: true, error: null, headers: {}, body: [{ id: 1 }], ...over };
}

describe('RouteConfigModal', () => {
    beforeEach(() => {
        [createMutate, updateMutate, testRouteMutate, testMutate, produceMutate].forEach((m) => m.mockClear());
        createState = stub(createMutate);
        updateState = stub(updateMutate);
        testRouteState = stub(testRouteMutate);
        testState = stub(testMutate);
        produceState = stub(produceMutate);
    });

    it('create mode: Testa is available without saving, and Save sends the recombined config', () => {
        render(<RouteConfigModal connector={connector} route={null} onClose={vi.fn()} onSaved={vi.fn()} />);

        // The single-modal win: the test action exists in create mode (no save-first).
        expect(screen.getByTestId('api-route-form-test')).toBeInTheDocument();
        expect(screen.getByTestId('api-route-form')).toHaveAttribute('aria-label', 'New route');

        fireEvent.change(screen.getByTestId('api-route-form-name'), { target: { value: 'Prodotti' } });
        fireEvent.change(screen.getByTestId('api-route-form-url'), { target: { value: '/products' } });
        fireEvent.click(screen.getByTestId('api-route-form-submit'));

        expect(createMutate).toHaveBeenCalledTimes(1);
        const [vars] = createMutate.mock.calls[0];
        expect(vars.connectorId).toBe(3);
        expect(vars.config.identity.name).toBe('Prodotti');
        // base + path recombine into the full canonical URL.
        expect(vars.config.request.url).toBe('https://api.acme.com/products');
    });

    it('edit mode: prefills from the BE config block and Save calls updateRoute', () => {
        const route = { id: 5, name: 'Catalogo', config: config({ identity: { name: 'Catalogo', slug: 'catalogo', description: 'Existing.', mode: 'tool' } }) } as unknown as ApiRoute;
        render(<RouteConfigModal connector={connector} route={route} onClose={vi.fn()} onSaved={vi.fn()} />);

        expect(screen.getByTestId('api-route-form-name')).toHaveValue('Catalogo');
        expect(screen.getByTestId('api-route-form-description')).toHaveValue('Existing.');

        fireEvent.change(screen.getByTestId('api-route-form-description'), { target: { value: 'Edited.' } });
        fireEvent.click(screen.getByTestId('api-route-form-submit'));

        expect(updateMutate).toHaveBeenCalledTimes(1);
        const [vars] = updateMutate.mock.calls[0];
        expect(vars.routeId).toBe(5);
        expect(vars.config.identity.description).toBe('Edited.');
    });

    it('param source toggles the conditional value / secret_ref field', () => {
        render(<RouteConfigModal connector={connector} route={null} onClose={vi.fn()} onSaved={vi.fn()} />);

        fireEvent.click(screen.getByTestId('api-route-form-param-add'));
        // llm (default): neither value nor secret_ref.
        expect(screen.queryByTestId('api-route-form-param-0-value')).not.toBeInTheDocument();
        expect(screen.queryByTestId('api-route-form-param-0-secret_ref')).not.toBeInTheDocument();

        fireEvent.change(screen.getByTestId('api-route-form-param-0-source'), { target: { value: 'secret' } });
        expect(screen.getByTestId('api-route-form-param-0-secret_ref')).toBeInTheDocument();
        expect(screen.queryByTestId('api-route-form-param-0-value')).not.toBeInTheDocument();

        fireEvent.change(screen.getByTestId('api-route-form-param-0-source'), { target: { value: 'fixed' } });
        expect(screen.getByTestId('api-route-form-param-0-value')).toBeInTheDocument();
        expect(screen.queryByTestId('api-route-form-param-0-secret_ref')).not.toBeInTheDocument();
    });

    it('"Configura con AI" fills the whole form from the returned config', () => {
        const produced: ProduceConfigResponse = {
            config: config({
                identity: { name: 'list_catalog', slug: null, description: 'Elenca il catalogo.', mode: 'tool' },
                response: { endpoint_type: 'list', items_path: 'data', transform: null, pagination: { type: 'cursor', next_cursor_path: 'meta.next' } },
                request: { http_method: 'GET', url: 'https://api.acme.com/catalog', auth_profile_id: null, params: [{ name: 'q', location: 'query', source: 'llm', type: 'string', required: false, description: null, sort_order: 0 }] },
            }),
            final_test: testResult() as never,
            source: 'openapi',
        };
        produceMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.(produced));
        render(<RouteConfigModal connector={connector} route={null} onClose={vi.fn()} onSaved={vi.fn()} />);

        fireEvent.click(screen.getByTestId('api-route-form-ai-configure'));

        expect(produceMutate).toHaveBeenCalled();
        // The whole form reflects the produced config.
        expect(screen.getByTestId('api-route-form-name')).toHaveValue('list_catalog');
        expect(screen.getByTestId('api-route-form-description')).toHaveValue('Elenca il catalogo.');
        expect(screen.getByTestId('api-route-form-endpoint_type-list')).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByTestId('api-route-form-items_path')).toHaveValue('data');
        expect(screen.getByTestId('api-route-form-param-0-name')).toHaveValue('q');
        // Verdict strip + dirty.
        expect(screen.getByTestId('api-route-form-ai-source')).toHaveTextContent('da OpenAPI');
        expect(screen.getByTestId('api-route-form-ai-final-test')).toHaveTextContent('OK');
        expect(screen.getByTestId('api-route-form-status')).toHaveTextContent('Modifiche non salvate');
    });

    it('Testa renders the outcome; invalid example-args JSON blocks the call', () => {
        // Invalid JSON → no mutate, error shown.
        render(<RouteConfigModal connector={connector} route={null} onClose={vi.fn()} onSaved={vi.fn()} />);
        fireEvent.change(screen.getByTestId('api-route-form-example-args'), { target: { value: '{ not json' } });
        fireEvent.click(screen.getByTestId('api-route-form-test'));
        expect(screen.getByTestId('api-route-form-example-args-error')).toBeInTheDocument();
        expect(testMutate).not.toHaveBeenCalled();

        // Valid → mutate fires and the result renders.
        const res: TestConfigResponse = { test: testResult() as never, endpoint_type: 'list', items_path: 'data', detected_pagination: null, item_count: 1 };
        testMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.(res));
        fireEvent.change(screen.getByTestId('api-route-form-example-args'), { target: { value: '{"q":"x"}' } });
        fireEvent.click(screen.getByTestId('api-route-form-test'));

        expect(testMutate).toHaveBeenCalledWith({ connectorId: 3, config: expect.anything(), exampleArgs: { q: 'x' } }, expect.anything());
        const result = screen.getByTestId('api-route-form-test-result');
        expect(result).toHaveAttribute('data-ok', 'true');
        expect(screen.getByTestId('api-route-form-test-endpoint-type')).toHaveAttribute('data-endpoint-type', 'list');
        expect(screen.getByTestId('api-route-form-response')).toBeInTheDocument();
    });
});
