import type {
    ApiConnector,
    ApiRoute,
    ApiRouteParameter,
    RouteConfig,
    RouteConfigParam,
} from './api-connectors.api';

/**
 * Pure helpers around the canonical config JSON — the modal's form model. Kept
 * out of the component so the URL split, the route→config mapping, the
 * group-diff (for the "just autofilled" flash) and the BE error mapping are unit
 * testable in isolation (R16).
 */

export function isFullUrl(value: string): boolean {
    return /^https?:\/\//i.test(value);
}

/** A fresh config for a NEW route on this connector (auth defaults to the connector's). */
export function emptyConfig(connector: ApiConnector): RouteConfig {
    return {
        identity: { name: '', slug: null, description: null, mode: 'tool' },
        request: {
            http_method: 'GET',
            url: '',
            auth_profile_id: connector.default_auth_profile_id,
            params: [],
        },
        response: { endpoint_type: 'auto', items_path: null, transform: null, pagination: null },
        options: { timeout_ms: null, cache_ttl_s: null, rate_limit: null },
    };
}

/** A blank parameter row. */
export function blankParam(): RouteConfigParam {
    return { name: '', location: 'query', source: 'llm', type: 'string', required: false, description: null, sort_order: 0 };
}

export function paramToConfig(p: ApiRouteParameter): RouteConfigParam {
    const row: RouteConfigParam = {
        name: p.name,
        location: p.location,
        source: p.source,
        type: p.type,
        required: p.required,
        description: p.description,
        sort_order: p.sort_order ?? 0,
    };
    if (p.source === 'fixed') row.value = p.value;
    if (p.source === 'secret') row.secret_ref = p.secret_ref;
    return row;
}

/** Build the form model from a full route — preferring the BE `config` block. */
export function routeToConfig(route: ApiRoute): RouteConfig {
    if (route.config) return route.config;

    return {
        identity: {
            name: route.name,
            slug: route.slug || null,
            description: route.description,
            mode: route.mode,
        },
        request: {
            http_method: route.http_method,
            url: route.url,
            auth_profile_id: route.auth_profile_id,
            params: (route.parameters ?? []).map(paramToConfig),
        },
        response: {
            endpoint_type: route.endpoint_type_locked && route.endpoint_type !== 'unknown' ? route.endpoint_type : 'auto',
            items_path: route.items_path,
            transform: route.output_transform as RouteConfig['response']['transform'],
            pagination: route.pagination,
        },
        options: {
            timeout_ms: route.timeout_ms,
            cache_ttl_s: route.cache_ttl_s,
            rate_limit: route.rate_limit,
        },
    };
}

/**
 * Split a full URL into a read-only connector-base prefix + an editable path,
 * for display only. When the URL isn't under the base (external / full), the
 * whole URL is the path and there is no prefix.
 */
export function splitUrl(base: string | null, url: string): { prefix: string | null; path: string } {
    const b = (base ?? '').replace(/\/$/, '');
    if (b && url.startsWith(b)) return { prefix: b, path: url.slice(b.length) };
    return { prefix: null, path: url };
}

/** Recombine the base + edited path into the full canonical URL stored in the config. */
export function joinUrl(base: string | null, path: string): string {
    const b = (base ?? '').replace(/\/$/, '');
    if (b && path && !isFullUrl(path)) return b + (path.startsWith('/') ? path : `/${path}`);
    return path;
}

/** Which top-level config groups changed — drives the green "just autofilled" flash. */
export function diffGroups(a: RouteConfig, b: RouteConfig): (keyof RouteConfig)[] {
    const groups: (keyof RouteConfig)[] = ['identity', 'request', 'response', 'options'];
    return groups.filter((g) => JSON.stringify(a[g]) !== JSON.stringify(b[g]));
}

/**
 * Map BE validation keys (`config.request.url`, `config.request.params.0.name`,
 * `config.identity.name`) onto the modal's flat field keys (`url`,
 * `param-0-name`, `name`) so per-field errors bind to `api-route-form-<key>-error`.
 */
export function mapConfigErrors(fieldErrors: Record<string, string>): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, msg] of Object.entries(fieldErrors)) {
        const param = key.match(/^config\.request\.params\.(\d+)\.(\w+)$/);
        if (param) {
            out[`param-${param[1]}-${param[2]}`] = msg;
            continue;
        }
        const grouped = key.match(/^config\.\w+\.(\w+)$/);
        if (grouped) {
            out[grouped[1]] = msg;
            continue;
        }
        out[key] = msg;
    }
    return out;
}
