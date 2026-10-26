import { chooseSelectOption } from '@/test-utils/select';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RelationEditor, type RelationEditorProps } from './RelationEditor';
import { apiConnectorsApi, type ApiRoute, type ApiRouteRelation, type ApiRouteSummary, type TestConfigResponse } from './api-connectors.api';

vi.mock('./api-connectors.api', () => ({ apiConnectorsApi: { testConfig: vi.fn() } }));
const routes: ApiRouteSummary[] = [
    { id: 1, name: 'List orders', slug: 'orders', status: 'active', mode: 'tool', endpoint_type: 'list', http_method: 'GET', last_test_status: 'ok' },
    { id: 2, name: 'Order detail', slug: 'detail', status: 'draft', mode: 'tool', endpoint_type: 'detail', http_method: 'GET', last_test_status: null },
    { id: 3, name: 'Other list', slug: 'other', status: 'active', mode: 'tool', endpoint_type: 'list', http_method: 'GET', last_test_status: null },
];
const list = { id: 1, api_connector_id: 7, name: 'List orders', slug: 'orders', endpoint_type: 'list', endpoint_type_locked: true, items_path: 'order', last_test_status: 'ok', last_test_at: '2026-10-21T20:00:00Z', last_test_payload: { envelope: 'not a field', order: [{ ord_ID: 3, Customer: { Name: 'Alice' } }, { ord_ID: 4, Customer: { Code: 'C' } }] }, parameters: [], http_method: 'GET', url: '/orders', auth_profile_id: 9, output_transform: null } as unknown as ApiRoute;
const detail = { id: 2, endpoint_type: 'detail', parameters: [{ name: 'ord_ID', location: 'query', source: 'llm', type: 'integer', required: true }, { name: 'secret', source: 'secret', type: 'string' }, { name: 'type', source: 'fixed', type: 'string' }] } as ApiRoute;
const relation = { id: 6, list_route_id: 1, detail_route_id: 2, name: 'Ordini elenco documento', field_map: [{ from: 'ord_ID', to_param: 'ord_ID' }] } as ApiRouteRelation;
const response = (body: unknown): TestConfigResponse => ({ test: { ok: true, is_json: true, status: 200, status_label: 'ok', body, error: null, headers: {} }, endpoint_type: 'list', items_path: 'order', detected_pagination: null, item_count: null });
function props(overrides: Partial<RelationEditorProps> = {}): RelationEditorProps {
    return { routes, relation, listRouteFull: list, detailRouteFull: detail, onSubmit: vi.fn(), onClose: vi.fn(), onSelectListRoute: vi.fn(), onSelectDetailRoute: vi.fn(), ...overrides };
}
beforeEach(() => vi.clearAllMocks());

describe('RelationEditor', () => {
    it('saves the real ord_ID mapping unchanged and retains label', () => {
        const options = props(); render(<RelationEditor {...options} />);
        fireEvent.submit(screen.getByRole('dialog'));
        expect(options.onSubmit).toHaveBeenCalledWith({ list_route_id: 1, detail_route_id: 2, field_map: [{ from: 'ord_ID', to_param: 'ord_ID', to_location: 'query' }], name: 'Ordini elenco documento' });
    });
    it('offers searchable real nested fields across every item and only configured LLM parameters', async () => {
        render(<RelationEditor {...props()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Campo lista 1' }));
        await userEvent.type(screen.getByRole('combobox', { name: 'Cerca Campo lista 1' }), 'Customer.Code');
        expect(screen.getByRole('option', { name: /Customer.Code/ })).toHaveTextContent('1/2 elementi');
        expect(screen.queryByRole('option', { name: /envelope/ })).not.toBeInTheDocument();
        await userEvent.keyboard('{Escape}');
        await userEvent.click(screen.getByRole('button', { name: 'Parametro dettaglio 1' }));
        expect(screen.getByRole('option', { name: /ord_ID/ })).toHaveTextContent('query · integer');
        expect(screen.queryByRole('option', { name: /secret|type/ })).not.toBeInTheDocument();
    });
    it('supports keyboard picker replacement and adds/removes rows', async () => {
        const options = props({ relation: { ...relation, field_map: [{ from: 'old', to_param: 'ord_ID' }] } });
        render(<RelationEditor {...options} />);
        await userEvent.click(screen.getByRole('button', { name: 'Campo lista 1' }));
        await userEvent.type(screen.getByRole('combobox', { name: 'Cerca Campo lista 1' }), 'ord_ID');
        await userEvent.keyboard('{Enter}');
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeEnabled();
        await userEvent.click(screen.getByTestId('api-route-relation-form-map-add'));
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeDisabled();
        await userEvent.click(screen.getByTestId('api-route-relation-form-map-1-remove'));
        fireEvent.submit(screen.getByRole('dialog'));
        expect(options.onSubmit).toHaveBeenCalledWith(expect.objectContaining({ field_map: [{ from: 'ord_ID', to_param: 'ord_ID', to_location: 'query' }] }));
    });
    it('keeps stale mappings visible, explains correction and blocks even direct form submit', () => {
        const options = props({ relation: { ...relation, field_map: [{ from: 'removed', to_param: 'secret' }] } });
        render(<RelationEditor {...options} />);
        expect(screen.getByRole('button', { name: 'Campo lista 1' })).toHaveTextContent('removed · da correggere');
        expect(screen.getByRole('button', { name: 'Parametro dettaglio 1' })).toHaveTextContent('secret · da correggere');
        fireEvent.submit(screen.getByRole('dialog'));
        expect(options.onSubmit).not.toHaveBeenCalled();
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeDisabled();
    });
    it('rejects incompatible scalar and collection mappings', () => {
        render(<RelationEditor {...props({ listRouteFull: { ...list, last_test_payload: { order: [{ ord_ID: [1, 2] }] } } })} />);
        expect(screen.getByText(/tipo o cardinalità incompatibile/)).toBeInTheDocument();
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeDisabled();
    });
    it('refreshes through the real configured test API, not generated sample data', async () => {
        vi.mocked(apiConnectorsApi.testConfig).mockResolvedValue(response({ order: [{ ord_ID: 10, fresh: true }] }));
        const requiredList = { ...list, parameters: [{ name: 'limit', location: 'query', source: 'llm', type: 'integer', required: true }] } as ApiRoute;
        render(<RelationEditor {...props({ listRouteFull: requiredList })} />);
        await userEvent.click(screen.getByText('Argomenti della chiamata'));
        await userEvent.type(screen.getByRole('textbox', { name: 'Argomento limit' }), '1');
        await userEvent.click(screen.getByRole('button', { name: 'Aggiorna dalla chiamata reale' }));
        await waitFor(() => expect(apiConnectorsApi.testConfig).toHaveBeenCalledWith(7, expect.objectContaining({ request: expect.objectContaining({ auth_profile_id: 9 }) }), { limit: 1 }));
        await userEvent.click(screen.getByRole('button', { name: 'Campo lista 1' }));
        expect(screen.getByRole('option', { name: /fresh/ })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /Customer/ })).not.toBeInTheDocument();
    });
    it('requires and type-checks guided arguments before making the call', async () => {
        render(<RelationEditor {...props({ listRouteFull: { ...list, parameters: [{ name: 'limit', location: 'query', source: 'llm', type: 'integer', required: true }] } as ApiRoute })} />);
        await userEvent.click(screen.getByRole('button', { name: 'Aggiorna dalla chiamata reale' }));
        expect(screen.getByRole('alert')).toHaveTextContent('Inserisci limit');
        expect(apiConnectorsApi.testConfig).not.toHaveBeenCalled();
    });
    it('keeps the previous successful response after a failed refresh', async () => {
        vi.mocked(apiConnectorsApi.testConfig).mockResolvedValue({ ...response(null), test: { ...response(null).test, ok: false, status: 401, error: 'Unauthorized' } });
        render(<RelationEditor {...props()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Aggiorna dalla chiamata reale' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Unauthorized');
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeEnabled();
    });
    it('explains empty successful lists and blocks stale mappings', async () => {
        vi.mocked(apiConnectorsApi.testConfig).mockResolvedValue(response({ order: [] }));
        render(<RelationEditor {...props()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Aggiorna dalla chiamata reale' }));
        expect(await screen.findByText(/l’elenco è vuoto/)).toBeInTheDocument();
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeDisabled();
    });
    it('does not treat an error response as field evidence', () => {
        render(<RelationEditor {...props({ listRouteFull: { ...list, last_test_status: 'http_401' } })} />);
        expect(screen.getByText(/Nessuna risposta reale riuscita/)).toBeInTheDocument();
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeDisabled();
    });
    it('ignores a refresh response after changing the list route', async () => {
        let complete!: (value: TestConfigResponse) => void;
        vi.mocked(apiConnectorsApi.testConfig).mockImplementation(() => new Promise((resolve) => { complete = resolve; }));
        const options = props();
        const { rerender } = render(<RelationEditor {...options} />);
        await userEvent.click(screen.getByRole('button', { name: 'Aggiorna dalla chiamata reale' }));
        await chooseSelectOption(screen.getByTestId('api-route-relation-form-list_route'), '3');
        rerender(<RelationEditor {...options} listRouteFull={{ ...list, id: 3, last_test_status: null, last_test_payload: null }} />);
        await act(async () => { complete(response({ order: [{ leaked: 'old request' }] })); });
        expect(screen.getByText(/Nessuna risposta reale riuscita/)).toBeInTheDocument();
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeDisabled();
        expect(options.onSelectListRoute).toHaveBeenCalledWith(3);
    });
    it('surfaces route loading errors and submit errors', () => {
        render(<RelationEditor {...props({ listRouteFull: null, listRouteError: 'Cannot load list', submitError: 'Save failed' })} />);
        expect(screen.getByText('Cannot load list')).toBeInTheDocument();
        expect(screen.getByTestId('api-route-relation-form-error')).toHaveTextContent('Save failed');
    });
    it('preserves a selected detail route even if its response taxonomy is list', async () => {
        render(<RelationEditor {...props({ detailRouteFull: { ...detail, endpoint_type: 'list' }, routes: routes.map((route) => route.id === 2 ? { ...route, endpoint_type: 'list' } : route) })} />);
        await userEvent.click(screen.getByTestId('api-route-relation-form-detail_route'));
        expect(screen.getByRole('option', { name: /Order detail/ })).toBeInTheDocument();
        await userEvent.keyboard('{Escape}');
        expect(screen.getByRole('alert')).toHaveTextContent('classificato come lista');
        expect(screen.getByTestId('api-route-relation-form-submit')).toBeDisabled();
    });
});
