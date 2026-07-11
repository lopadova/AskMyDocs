import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DrillTestPanel, type DrillTestPanelProps } from './DrillTestPanel';
import type { ApiRouteRelation, DrillResult } from './api-connectors.api';

const relation: ApiRouteRelation = {
    id: 7,
    api_connector_id: 1,
    list_route_id: 1,
    detail_route_id: 2,
    name: null,
    description: null,
    field_map: [{ from: 'id', to_param: 'id' }],
    sort_order: 0,
    list_route: { id: 1, name: 'List', slug: 'list_users', endpoint_type: 'list' },
    detail_route: { id: 2, name: 'Detail', slug: 'user_detail', endpoint_type: 'detail' },
    created_at: null,
    updated_at: null,
};

function setup(props: Partial<DrillTestPanelProps> = {}) {
    const onDrill = vi.fn();
    const onClose = vi.fn();
    render(<DrillTestPanel relation={relation} result={null} onDrill={onDrill} onClose={onClose} {...props} />);
    return { onDrill, onClose };
}

describe('DrillTestPanel', () => {
    it('drills by item index', async () => {
        const { onDrill } = setup();
        const input = screen.getByTestId('api-route-drill-item-index');
        await userEvent.clear(input);
        await userEvent.type(input, '2');
        await userEvent.click(screen.getByTestId('api-route-drill-run'));
        expect(onDrill).toHaveBeenCalledWith({ item_index: 2 });
    });

    it('drills with an explicit JSON item', async () => {
        const { onDrill } = setup();
        // fireEvent.change — userEvent.type treats `{` / `[` as special keys.
        fireEvent.change(screen.getByTestId('api-route-drill-item-json'), { target: { value: '{"id": 9}' } });
        await userEvent.click(screen.getByTestId('api-route-drill-run-json'));
        expect(onDrill).toHaveBeenCalledWith({ list_item: { id: 9 } });
    });

    it('rejects a non-object JSON item without calling onDrill', async () => {
        const { onDrill } = setup();
        fireEvent.change(screen.getByTestId('api-route-drill-item-json'), { target: { value: '[1,2]' } });
        await userEvent.click(screen.getByTestId('api-route-drill-run-json'));
        expect(onDrill).not.toHaveBeenCalled();
        expect(screen.getByTestId('api-route-drill-item-json-error')).toBeInTheDocument();
    });

    it('renders the mapped arguments and the raw detail result', () => {
        const result: DrillResult = {
            arguments: { id: 5 },
            result: { ok: true, status: 200, status_label: 'ok', is_json: true, error: null, headers: {}, body: { id: 5, name: 'Zoe' }, duration_ms: 12 },
        };
        setup({ result });
        expect(screen.getByTestId('api-route-drill-args')).toHaveTextContent('"id": 5');
        expect(screen.getByTestId('api-route-drill-status')).toHaveTextContent('OK');
        expect(screen.getByTestId('api-route-drill-body')).toHaveTextContent('Zoe');
    });

    it('surfaces a drill error (R14)', () => {
        setup({ error: 'field not found' });
        expect(screen.getByTestId('api-route-drill-error')).toHaveTextContent('field not found');
    });
});
