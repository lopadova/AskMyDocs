import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { HealthStrip } from './HealthStrip';

describe('HealthStrip', () => {
    it('rolls up to ok when every concern is ok', () => {
        render(
            <HealthStrip
                state="ready"
                health={{
                    db_ok: 'ok',
                    pgvector_ok: 'ok',
                    queue_ok: 'ok',
                    kb_disk_ok: 'ok',
                    embedding_provider_ok: 'ok',
                    chat_provider_ok: 'ok',
                    checked_at: new Date().toISOString(),
                }}
            />,
        );

        expect(screen.getByTestId('dashboard-health')).toHaveAttribute('data-state', 'ok');
        expect(screen.getByTestId('health-db')).toHaveAttribute('data-state', 'ok');
        expect(screen.getByTestId('health-queue')).toHaveAttribute('data-state', 'ok');
    });

    it('rolls up to degraded when any concern is degraded', () => {
        render(
            <HealthStrip
                state="ready"
                health={{
                    db_ok: 'ok',
                    pgvector_ok: 'ok',
                    queue_ok: 'degraded',
                    kb_disk_ok: 'ok',
                    embedding_provider_ok: 'ok',
                    chat_provider_ok: 'ok',
                    checked_at: new Date().toISOString(),
                }}
            />,
        );

        expect(screen.getByTestId('dashboard-health')).toHaveAttribute('data-state', 'degraded');
        expect(screen.getByTestId('health-queue')).toHaveAttribute('data-state', 'degraded');
    });

    it('rolls up to down when any concern is down — down beats degraded', () => {
        render(
            <HealthStrip
                state="ready"
                health={{
                    db_ok: 'down',
                    pgvector_ok: 'ok',
                    queue_ok: 'degraded',
                    kb_disk_ok: 'ok',
                    embedding_provider_ok: 'ok',
                    chat_provider_ok: 'ok',
                    checked_at: new Date().toISOString(),
                }}
            />,
        );

        expect(screen.getByTestId('dashboard-health')).toHaveAttribute('data-state', 'down');
    });

    it('renders a loading shimmer before the probe returns', () => {
        render(<HealthStrip state="loading" health={null} />);
        expect(screen.getByTestId('dashboard-health')).toHaveAttribute('data-state', 'loading');
    });
});
