import { describe, it, expect } from 'vitest';
import type { ApiConnector, ApiRoute, RouteConfig } from './api-connectors.api';
import { blankParam, diffGroups, emptyConfig, joinUrl, mapConfigErrors, paramToConfig, routeToConfig, splitUrl } from './route-config';

const connector = { id: 3, base_url: 'https://api.acme.com', default_auth_profile_id: 4 } as unknown as ApiConnector;

describe('route-config helpers', () => {
    it('emptyConfig defaults auth to the connector default and mode to tool', () => {
        const c = emptyConfig(connector);
        expect(c.identity.mode).toBe('tool');
        expect(c.request.auth_profile_id).toBe(4);
        expect(c.request.params).toEqual([]);
        expect(c.response.endpoint_type).toBe('auto');
    });

    it('splitUrl/joinUrl round-trip a path under the base and pass full URLs through', () => {
        expect(splitUrl('https://api.acme.com', 'https://api.acme.com/users')).toEqual({ prefix: 'https://api.acme.com', path: '/users' });
        expect(joinUrl('https://api.acme.com', '/users')).toBe('https://api.acme.com/users');
        // A URL not under the base is shown whole, with no prefix, and passes through join.
        expect(splitUrl('https://api.acme.com', 'https://other.com/x')).toEqual({ prefix: null, path: 'https://other.com/x' });
        expect(joinUrl('https://api.acme.com', 'https://other.com/x')).toBe('https://other.com/x');
    });

    it('routeToConfig prefers the BE config block', () => {
        const cfg = { identity: { name: 'FromBE' } } as unknown as RouteConfig;
        expect(routeToConfig({ config: cfg } as unknown as ApiRoute)).toBe(cfg);
    });

    it('routeToConfig derives from flat fields when config is absent, encoding auto vs locked', () => {
        const route = {
            name: 'Users', slug: 'users', description: 'd', mode: 'tool', http_method: 'GET',
            url: 'https://x/users', auth_profile_id: null,
            endpoint_type: 'list', endpoint_type_locked: false, items_path: 'data',
            output_transform: null, pagination: null, timeout_ms: null, cache_ttl_s: null, rate_limit: null,
            parameters: [{ name: 'q', location: 'query', source: 'llm', type: 'string', required: false, value: null, secret_ref: null, description: null, sort_order: 0 }],
        } as unknown as ApiRoute;

        const c = routeToConfig(route);
        // Unlocked detector-set list → 'auto'.
        expect(c.response.endpoint_type).toBe('auto');
        expect(c.request.params[0].name).toBe('q');
    });

    it('paramToConfig scopes value to fixed and secret_ref to secret', () => {
        const fixed = paramToConfig({ source: 'fixed', value: 'json', name: 'fmt', location: 'query', type: 'string', required: false, secret_ref: null, description: null, sort_order: 0 } as never);
        expect(fixed.value).toBe('json');
        expect(fixed.secret_ref).toBeUndefined();
        const secret = paramToConfig({ source: 'secret', secret_ref: 'api_key', value: null, name: 'k', location: 'header', type: 'string', required: false, description: null, sort_order: 0 } as never);
        expect(secret.secret_ref).toBe('api_key');
        expect(secret.value).toBeUndefined();
    });

    it('diffGroups reports only the changed top-level groups', () => {
        const a = emptyConfig(connector);
        const b = { ...a, response: { ...a.response, endpoint_type: 'list' as const } };
        expect(diffGroups(a, b)).toEqual(['response']);
        expect(diffGroups(a, a)).toEqual([]);
    });

    it('mapConfigErrors flattens BE keys onto field/param keys', () => {
        const out = mapConfigErrors({
            'config.identity.name': 'Required.',
            'config.request.url': 'Bad url.',
            'config.request.params.0.name': 'Param name.',
        });
        expect(out).toEqual({ name: 'Required.', url: 'Bad url.', 'param-0-name': 'Param name.' });
    });

    it('blankParam is an llm query string param', () => {
        expect(blankParam()).toMatchObject({ location: 'query', source: 'llm', type: 'string', required: false });
    });
});
