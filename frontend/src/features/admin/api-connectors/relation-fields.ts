import type { ApiRoute } from './api-connectors.api';

/*
 * Pure helpers (no React) so the relation editor's field suggestions can be
 * unit-tested. They derive:
 *   - the LIST route's item field names (dot-paths) from its inferred
 *     output_schema walked to items_path — the `from` side of a field map;
 *   - the DETAIL route's LLM parameter names — the `to_param` side (only `llm`
 *     params can be injected from a list item; R5).
 */

/**
 * The JSON schema of a single list item, walked from a list route's
 * output_schema at its items_path ('' / null = top-level array). Mirrors the
 * backend ApiRouteController::itemSchema().
 */
export function listItemSchema(route: ApiRoute): Record<string, unknown> | null {
    if (route.endpoint_type !== 'list') return null;
    let node: unknown = route.output_schema;
    if (!isRecord(node)) return null;

    const path = (route.items_path ?? '').trim();
    if (path !== '') {
        for (const segment of path.split('.')) {
            const props = isRecord(node) ? node.properties : undefined;
            node = isRecord(props) ? props[segment] : undefined;
            if (!isRecord(node)) return null;
        }
    }

    const items = isRecord(node) ? node.items : undefined;
    return isRecord(items) ? items : null;
}

/**
 * The top-level field names of a list item, e.g. `['id', 'name']`. Suggestions
 * for a field map's `from` (an operator can still type a nested dot-path).
 */
export function listItemFieldNames(route: ApiRoute): string[] {
    const schema = listItemSchema(route);
    const props = schema && isRecord(schema.properties) ? schema.properties : null;
    return props ? Object.keys(props) : [];
}

/** The LLM-sourced parameter names of a detail route — the valid `to_param` set. */
export function detailLlmParamNames(route: ApiRoute): string[] {
    if (!route.parameters) return [];
    return route.parameters.filter((p) => p.source === 'llm').map((p) => p.name);
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
