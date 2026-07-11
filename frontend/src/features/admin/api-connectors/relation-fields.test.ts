import { describe, expect, it } from 'vitest';
import { detailLlmParamNames, listItemFieldNames, listItemSchema } from './relation-fields';
import type { ApiRoute } from './api-connectors.api';

function route(overrides: Partial<ApiRoute>): ApiRoute {
    return {
        id: 1,
        api_connector_id: 1,
        project_key: null,
        name: 'R',
        slug: 'r',
        description: null,
        http_method: 'GET',
        url: 'https://x',
        auth_profile_id: null,
        mode: 'tool',
        status: 'tested',
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

describe('listItemSchema', () => {
    it('reads the item schema of a top-level array list', () => {
        const r = route({
            endpoint_type: 'list',
            items_path: '',
            output_schema: { type: 'array', items: { type: 'object', properties: { id: {}, name: {} } } },
        });
        expect(listItemSchema(r)).toEqual({ type: 'object', properties: { id: {}, name: {} } });
    });

    it('walks an envelope items_path', () => {
        const r = route({
            endpoint_type: 'list',
            items_path: 'data',
            output_schema: {
                type: 'object',
                properties: { data: { type: 'array', items: { type: 'object', properties: { id: {} } } } },
            },
        });
        expect(listItemSchema(r)).toEqual({ type: 'object', properties: { id: {} } });
    });

    it('returns null for a detail route', () => {
        expect(listItemSchema(route({ endpoint_type: 'detail' }))).toBeNull();
    });
});

describe('listItemFieldNames', () => {
    it('returns the item field names', () => {
        const r = route({
            endpoint_type: 'list',
            items_path: 'data',
            output_schema: {
                type: 'object',
                properties: { data: { type: 'array', items: { type: 'object', properties: { id: {}, email: {} } } } },
            },
        });
        expect(listItemFieldNames(r)).toEqual(['id', 'email']);
    });

    it('is empty when the schema is missing', () => {
        expect(listItemFieldNames(route({ endpoint_type: 'list', output_schema: null }))).toEqual([]);
    });
});

describe('detailLlmParamNames', () => {
    it('returns only the llm param names', () => {
        const r = route({
            parameters: [
                { id: 1, name: 'id', location: 'path', source: 'llm', type: 'integer', required: true, value: null, secret_ref: null, description: null, sort_order: 0 },
                { id: 2, name: 'key', location: 'query', source: 'secret', type: 'string', required: false, value: null, secret_ref: 'api_key', description: null, sort_order: 1 },
                { id: 3, name: 'fmt', location: 'query', source: 'fixed', type: 'string', required: false, value: 'json', secret_ref: null, description: null, sort_order: 2 },
            ],
        });
        expect(detailLlmParamNames(r)).toEqual(['id']);
    });
});
