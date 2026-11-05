import type { ApiRoute, ParamType, RouteConfigParam } from './api-connectors.api';

export interface RelationSourceField {
    path: string;
    types: string[];
    present: number;
    total: number;
    cardinality: 'one' | 'many';
}

export function listItemSchema(route: ApiRoute): Record<string, unknown> | null {
    if (route.endpoint_type !== 'list') return null;
    let node: unknown = route.output_schema;
    for (const segment of (route.items_path ?? '').split('.').filter(Boolean)) {
        node = isRecord(node) && isRecord(node.properties) ? node.properties[segment] : undefined;
    }
    return isRecord(node) && isRecord(node.items) ? node.items : null;
}

export function hasSuccessfulResponse(route: ApiRoute): boolean {
    const status = route.last_test_status;
    return status === 'ok' || (typeof status === 'number' && status >= 200 && status < 300);
}

/** Only real successful responses are evidence. Schemas can be obsolete or AI-produced. */
export function listResponseItems(route: ApiRoute, body: unknown = route.last_test_payload): unknown[] | null {
    if (route.endpoint_type !== 'list') return null;
    let node = body;
    for (const segment of (route.items_path ?? '').split('.').filter(Boolean)) {
        node = isRecord(node) || Array.isArray(node) ? (node as Record<string, unknown>)[segment] : undefined;
    }
    return Array.isArray(node) ? node : null;
}

export function listItemFields(route: ApiRoute, body?: unknown): RelationSourceField[] {
    if (body === undefined && !hasSuccessfulResponse(route)) return [];
    const items = listResponseItems(route, body === undefined ? route.last_test_payload : body);
    if (!items) return [];
    const fields = new Map<string, { types: Set<string>; present: number }>();
    function walk(value: Record<string, unknown>, prefix = '') {
        for (const [name, child] of Object.entries(value)) {
            // Dots in a literal key cannot be read by the package's dot-path mapper.
            if (name.includes('.')) continue;
            const path = prefix ? `${prefix}.${name}` : name;
            const type = child === null ? 'null' : Array.isArray(child) ? 'array' : typeof child === 'number' ? (Number.isInteger(child) ? 'integer' : 'number') : typeof child;
            const field = fields.get(path) ?? { types: new Set<string>(), present: 0 };
            field.types.add(type);
            field.present += 1;
            fields.set(path, field);
            if (isRecord(child)) walk(child, path);
        }
    }
    items.forEach((item) => { if (isRecord(item)) walk(item); });
    return [...fields].map(([path, field]) => ({ path, types: [...field.types].sort(), present: field.present, total: items.length, cardinality: field.types.has('array') ? 'many' : 'one' }));
}

export function listItemFieldNames(route: ApiRoute): string[] {
    return listItemFields(route).map((field) => field.path);
}

export function detailLlmParams(route: ApiRoute): RouteConfigParam[] {
    return (route.config?.request.params ?? route.parameters ?? []).filter((param) => param.source === 'llm').map((param) => ({ ...param, sort_order: param.sort_order ?? 0 }));
}

export function detailLlmParamNames(route: ApiRoute): string[] {
    return detailLlmParams(route).map((param) => param.name);
}

/** The connector relation mapper currently accepts only scalar sources. */
export function compatibleField(field: RelationSourceField, target: ParamType): boolean {
    if (target === 'array' || target === 'object' || field.cardinality === 'many') return false;
    const types = field.types.filter((type) => type !== 'null');
    return types.length > 0 && types.every((type) => type === target || (target === 'number' && type === 'integer'));
}

export function parseRelationArgument(value: string, type: ParamType): unknown {
    if (type === 'string') return value;
    const parsed: unknown = JSON.parse(value);
    if ((type === 'integer' && (typeof parsed !== 'number' || !Number.isInteger(parsed))) ||
        (type === 'number' && typeof parsed !== 'number') ||
        (type === 'boolean' && typeof parsed !== 'boolean') ||
        (type === 'array' && !Array.isArray(parsed)) ||
        (type === 'object' && !isRecord(parsed))) throw new Error(`Valore non valido per ${type}`);
    return parsed;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
