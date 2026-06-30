import type { RouteStatus } from './api-connectors.api';

/*
 * Pure helper extracted so the route status badge styling can be unit-tested
 * without React. Mirrors the connectors feature's `statusBadgeStyle()` posture.
 */

export interface RouteStatusBadge {
    label: string;
    background: string;
    border: string;
    color: string;
}

export function routeStatusBadge(status: RouteStatus): RouteStatusBadge {
    switch (status) {
        case 'active':
            return {
                label: 'Active',
                background: 'rgba(16, 185, 129, 0.16)',
                border: 'rgba(16, 185, 129, 0.45)',
                color: '#34d399',
            };
        case 'tested':
            return {
                label: 'Tested',
                background: 'rgba(59, 130, 246, 0.16)',
                border: 'rgba(59, 130, 246, 0.45)',
                color: '#60a5fa',
            };
        case 'disabled':
            return {
                label: 'Disabled',
                background: 'rgba(100, 116, 139, 0.16)',
                border: 'rgba(100, 116, 139, 0.45)',
                color: '#94a3b8',
            };
        case 'draft':
        default:
            return {
                label: 'Draft',
                background: 'rgba(250, 204, 21, 0.16)',
                border: 'rgba(250, 204, 21, 0.45)',
                color: '#fbbf24',
            };
    }
}
