import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ApiConnectorLauncherCard } from './ApiConnectorLauncherCard';

/*
 * Launcher card shown in the Connectors gallery for the API connector.
 * Presentational — no query/router context needed. R16: the "fires onOpen when
 * the CTA is clicked" test actually clicks the CTA; each state test asserts the
 * branch it names.
 */

describe('ApiConnectorLauncherCard', () => {
    it('renders the name, description and a ready state', () => {
        render(
            <ApiConnectorLauncherCard connectorCount={0} activeToolCount={0} onOpen={vi.fn()} />,
        );

        expect(screen.getByTestId('api-connector-launcher-name')).toHaveTextContent('API Connector');
        expect(screen.getByTestId('api-connector-launcher-card')).toHaveAttribute('data-state', 'ready');
        // The icon is decorative — not exposed to assistive tech.
        expect(screen.getByTestId('api-connector-launcher-icon')).toHaveAttribute('aria-hidden', 'true');
    });

    it('shows the "get started" CTA and empty status when nothing is configured', () => {
        render(
            <ApiConnectorLauncherCard connectorCount={0} activeToolCount={0} onOpen={vi.fn()} />,
        );

        expect(screen.getByTestId('api-connector-launcher-open')).toHaveTextContent('Get started');
        expect(screen.getByTestId('api-connector-launcher-empty')).toBeInTheDocument();
        expect(screen.queryByTestId('api-connector-launcher-count')).not.toBeInTheDocument();
    });

    it('shows the configured counts and a "manage" CTA when connectors exist', () => {
        render(
            <ApiConnectorLauncherCard connectorCount={3} activeToolCount={5} onOpen={vi.fn()} />,
        );

        expect(screen.getByTestId('api-connector-launcher-open')).toHaveTextContent('Manage');
        const count = screen.getByTestId('api-connector-launcher-count');
        expect(count).toHaveTextContent('3');
        expect(count).toHaveTextContent('connectors');
        expect(count).toHaveTextContent('5');
        expect(count).toHaveTextContent('active tools');
        expect(screen.getByTestId('api-connector-launcher-card')).toHaveAttribute(
            'data-connector-count',
            '3',
        );
    });

    it('singularises the count labels for exactly one connector / tool', () => {
        render(
            <ApiConnectorLauncherCard connectorCount={1} activeToolCount={1} onOpen={vi.fn()} />,
        );

        const count = screen.getByTestId('api-connector-launcher-count');
        expect(count).toHaveTextContent('1 connector ·');
        expect(count).toHaveTextContent('1 active tool');
    });

    it('surfaces the loading state without a count', () => {
        render(
            <ApiConnectorLauncherCard connectorCount={0} activeToolCount={0} isLoading onOpen={vi.fn()} />,
        );

        expect(screen.getByTestId('api-connector-launcher-card')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('api-connector-launcher-status')).toHaveTextContent('Loading status');
        expect(screen.queryByTestId('api-connector-launcher-count')).not.toBeInTheDocument();
    });

    it('degrades to a usable card when the status probe errors (R14)', async () => {
        const onOpen = vi.fn();
        render(
            <ApiConnectorLauncherCard connectorCount={0} activeToolCount={0} isError onOpen={onOpen} />,
        );

        expect(screen.getByTestId('api-connector-launcher-card')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('api-connector-launcher-status')).toHaveTextContent('Status unavailable');
        // The CTA must still work even though the count is unknown.
        await userEvent.click(screen.getByTestId('api-connector-launcher-open'));
        expect(onOpen).toHaveBeenCalledTimes(1);
    });

    it('fires onOpen when the CTA is clicked', async () => {
        const onOpen = vi.fn();
        render(
            <ApiConnectorLauncherCard connectorCount={2} activeToolCount={0} onOpen={onOpen} />,
        );

        await userEvent.click(screen.getByTestId('api-connector-launcher-open'));
        expect(onOpen).toHaveBeenCalledTimes(1);
    });
});
