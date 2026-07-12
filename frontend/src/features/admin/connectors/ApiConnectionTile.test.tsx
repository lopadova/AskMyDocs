import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ApiConnectionTile } from './ApiConnectionTile';
import type { ApiConnector, ApiRouteSummary } from '../api-connectors/api-connectors.api';

function route(status: ApiRouteSummary['status']): ApiRouteSummary {
    return {
        id: 1,
        name: 'r',
        slug: 'r',
        status,
        mode: 'tool',
        endpoint_type: 'detail',
        http_method: 'GET',
        last_test_status: null,
    };
}

function connector(over: Partial<ApiConnector> = {}): ApiConnector {
    return {
        id: 7,
        project_key: null,
        name: 'Weather API',
        description: null,
        base_url: 'https://api.weather.example/v1',
        default_auth_profile_id: null,
        headers: {},
        is_active: true,
        routes: [],
        created_at: null,
        updated_at: null,
        ...over,
    };
}

describe('ApiConnectionTile', () => {
    it('shows the name, the base-URL host and the route / active-tool counts', () => {
        render(
            <ApiConnectionTile
                connector={connector({ routes: [route('active'), route('draft')] })}
                onManage={vi.fn()}
                onEdit={vi.fn()}
                onRemove={vi.fn()}
            />,
        );

        expect(screen.getByTestId('api-connection-tile-7')).toBeInTheDocument();
        expect(screen.getByTestId('api-connection-tile-7-base-url')).toHaveTextContent('api.weather.example');
        // 2 routes, exactly 1 active → 1 live tool.
        expect(screen.getByTestId('api-connection-tile-7-routes')).toHaveTextContent('2 routes · 1 active tool');
        // Active connector → no "Disabled" badge.
        expect(screen.queryByTestId('api-connection-tile-7-disabled')).not.toBeInTheDocument();
    });

    it('flags a disabled connector and copes with a missing base URL', () => {
        render(
            <ApiConnectionTile
                connector={connector({ is_active: false, base_url: null })}
                onManage={vi.fn()}
                onEdit={vi.fn()}
                onRemove={vi.fn()}
            />,
        );

        expect(screen.getByTestId('api-connection-tile-7')).toHaveAttribute('data-active', 'false');
        expect(screen.getByTestId('api-connection-tile-7-disabled')).toBeInTheDocument();
        expect(screen.getByTestId('api-connection-tile-7-base-url')).toHaveTextContent('no base URL');
        expect(screen.getByTestId('api-connection-tile-7-routes')).toHaveTextContent('0 routes · 0 active tools');
    });

    it('fires Manage / Edit / Remove callbacks', () => {
        const onManage = vi.fn();
        const onEdit = vi.fn();
        const onRemove = vi.fn();
        const c = connector();
        render(<ApiConnectionTile connector={c} onManage={onManage} onEdit={onEdit} onRemove={onRemove} />);

        fireEvent.click(screen.getByTestId('api-connection-tile-7-manage'));
        expect(onManage).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByTestId('api-connection-tile-7-edit'));
        expect(onEdit).toHaveBeenCalledWith(c);

        fireEvent.click(screen.getByTestId('api-connection-tile-7-remove'));
        expect(onRemove).toHaveBeenCalledWith(c);
    });
});
