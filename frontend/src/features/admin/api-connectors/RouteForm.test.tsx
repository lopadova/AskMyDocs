import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RouteForm, type RouteFormProps } from './RouteForm';
import type { ApiRoute } from './api-connectors.api';

function setup(props: Partial<RouteFormProps> = {}) {
    const onSubmit = vi.fn();
    const onClose = vi.fn();
    render(<RouteForm authProfiles={[]} onSubmit={onSubmit} onClose={onClose} {...props} />);
    return { onSubmit, onClose };
}

/** A minimal persisted route for the edit-mode cases. */
function route(overrides: Partial<ApiRoute> = {}): ApiRoute {
    return {
        id: 1,
        api_connector_id: 1,
        project_key: null,
        name: 'R',
        slug: 'r',
        description: null,
        http_method: 'GET',
        url: 'https://api.example.com/things',
        auth_profile_id: null,
        mode: 'tool',
        status: 'draft',
        endpoint_type: 'unknown',
        endpoint_type_locked: false,
        items_path: null,
        timeout_ms: null,
        cache_ttl_s: null,
        rate_limit: null,
        input_schema: null,
        output_schema: null,
        param_mapping: null,
        tool_definition: null,
        output_transform: null,
        last_test_at: null,
        last_test_status: null,
        last_test_payload: null,
        parameters: [],
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('RouteForm endpoint-type control', () => {
    it('defaults a new route to Auto and hides the items-path field', () => {
        setup();
        expect(screen.getByTestId('api-route-form-endpoint_type-auto')).toBeChecked();
        expect(screen.getByTestId('api-route-form-endpoint_type-list')).not.toBeChecked();
        // items_path is only shown for an explicit list.
        expect(screen.queryByTestId('api-route-form-items_path')).not.toBeInTheDocument();
    });

    it('reveals the items-path field only when List is selected', async () => {
        setup();
        await userEvent.click(screen.getByTestId('api-route-form-endpoint_type-list'));
        expect(screen.getByTestId('api-route-form-items_path')).toBeInTheDocument();
        await userEvent.click(screen.getByTestId('api-route-form-endpoint_type-detail'));
        expect(screen.queryByTestId('api-route-form-items_path')).not.toBeInTheDocument();
    });

    it('pre-selects a locked override when editing a typed route', () => {
        setup({ route: route({ endpoint_type: 'detail', endpoint_type_locked: true }) });
        expect(screen.getByTestId('api-route-form-endpoint_type-detail')).toBeChecked();
        expect(screen.getByTestId('api-route-form-endpoint_type-auto')).not.toBeChecked();
    });

    it('submits endpoint_type=list with the typed items_path', async () => {
        const { onSubmit } = setup();
        await userEvent.type(screen.getByTestId('api-route-form-name'), 'List users');
        await userEvent.type(screen.getByTestId('api-route-form-url'), 'https://api.example.com/users');
        await userEvent.click(screen.getByTestId('api-route-form-endpoint_type-list'));
        await userEvent.type(screen.getByTestId('api-route-form-items_path'), 'data');
        fireEvent.submit(screen.getByTestId('api-route-form'));

        expect(onSubmit).toHaveBeenCalledTimes(1);
        const payload = onSubmit.mock.calls[0][0];
        expect(payload.endpoint_type).toBe('list');
        expect(payload.items_path).toBe('data');
    });

    it('submits endpoint_type=auto with items_path left to the detector (undefined)', async () => {
        const { onSubmit } = setup();
        await userEvent.type(screen.getByTestId('api-route-form-name'), 'Anything');
        await userEvent.type(screen.getByTestId('api-route-form-url'), 'https://api.example.com/x');
        fireEvent.submit(screen.getByTestId('api-route-form'));

        const payload = onSubmit.mock.calls[0][0];
        expect(payload.endpoint_type).toBe('auto');
        expect(payload.items_path).toBeUndefined();
    });
});
