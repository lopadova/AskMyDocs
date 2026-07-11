import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RelationEditor, type RelationEditorProps } from './RelationEditor';
import type { ApiRouteSummary } from './api-connectors.api';

const routes: ApiRouteSummary[] = [
    { id: 1, name: 'List users', slug: 'list_users', status: 'active', mode: 'tool', endpoint_type: 'list', http_method: 'GET', last_test_status: 200 },
    { id: 2, name: 'User detail', slug: 'user_detail', status: 'active', mode: 'tool', endpoint_type: 'detail', http_method: 'GET', last_test_status: 200 },
    { id: 3, name: 'Untyped', slug: 'untyped', status: 'draft', mode: 'tool', endpoint_type: 'unknown', http_method: 'GET', last_test_status: null },
];

function setup(props: Partial<RelationEditorProps> = {}) {
    const onSubmit = vi.fn();
    const onClose = vi.fn();
    const onSelectListRoute = vi.fn();
    const onSelectDetailRoute = vi.fn();
    render(
        <RelationEditor
            routes={routes}
            onSelectListRoute={onSelectListRoute}
            onSelectDetailRoute={onSelectDetailRoute}
            onSubmit={onSubmit}
            onClose={onClose}
            {...props}
        />,
    );
    return { onSubmit, onClose, onSelectListRoute, onSelectDetailRoute };
}

describe('RelationEditor', () => {
    it('filters the selects by endpoint type (list vs detail)', () => {
        setup();
        const listSelect = screen.getByTestId('api-route-relation-form-list_route');
        const detailSelect = screen.getByTestId('api-route-relation-form-detail_route');
        // list select offers only the list route (+ placeholder); detail only the detail route.
        expect(listSelect.querySelectorAll('option')).toHaveLength(2);
        expect(detailSelect.querySelectorAll('option')).toHaveLength(2);
        expect(screen.getByRole('option', { name: /List users/ })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: /User detail/ })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /Untyped/ })).not.toBeInTheDocument();
    });

    it('notifies the parent to fetch the selected route (for field suggestions)', async () => {
        const { onSelectListRoute } = setup();
        await userEvent.selectOptions(screen.getByTestId('api-route-relation-form-list_route'), '1');
        expect(onSelectListRoute).toHaveBeenCalledWith(1);
    });

    it('adds and removes field-map rows', async () => {
        setup();
        expect(screen.getByTestId('api-route-relation-form-map-0')).toBeInTheDocument();
        await userEvent.click(screen.getByTestId('api-route-relation-form-map-add'));
        expect(screen.getByTestId('api-route-relation-form-map-1')).toBeInTheDocument();
        await userEvent.click(screen.getByTestId('api-route-relation-form-map-1-remove'));
        expect(screen.queryByTestId('api-route-relation-form-map-1')).not.toBeInTheDocument();
    });

    it('submits the relation payload with the field map', async () => {
        const { onSubmit } = setup();
        await userEvent.selectOptions(screen.getByTestId('api-route-relation-form-list_route'), '1');
        await userEvent.selectOptions(screen.getByTestId('api-route-relation-form-detail_route'), '2');
        await userEvent.type(screen.getByTestId('api-route-relation-form-map-0-from'), 'id');
        await userEvent.type(screen.getByTestId('api-route-relation-form-map-0-to_param'), 'id');
        fireEvent.submit(screen.getByTestId('api-route-relation-form'));

        expect(onSubmit).toHaveBeenCalledTimes(1);
        expect(onSubmit.mock.calls[0][0]).toMatchObject({
            list_route_id: 1,
            detail_route_id: 2,
            field_map: [{ from: 'id', to_param: 'id' }],
        });
    });

    it('surfaces a submit error (R14)', () => {
        setup({ submitError: 'boom' });
        expect(screen.getByTestId('api-route-relation-form-error')).toHaveTextContent('boom');
    });
});
