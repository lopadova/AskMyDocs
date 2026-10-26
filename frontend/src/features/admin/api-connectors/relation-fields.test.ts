import { describe, expect, it } from 'vitest';
import { compatibleField, detailLlmParamNames, detailLlmParams, listItemFieldNames, listItemFields, listItemSchema, listResponseItems, parseRelationArgument } from './relation-fields';
import type { ApiRoute } from './api-connectors.api';

function route(overrides: Partial<ApiRoute>): ApiRoute {
    return { endpoint_type: 'list', items_path: 'data.orders', last_test_status: 'ok', last_test_payload: null, parameters: [], ...overrides } as ApiRoute;
}

describe('real list response fields', () => {
    it('walks the actual items_path, preserves case and unions every item with nested types', () => {
        const fields = listItemFields(route({ last_test_payload: { total: 2, data: { orders: [{ ord_ID: 123, Customer: { Name: 'A' }, lines: [] }, { ord_ID: 456, Customer: { ID: 'C' }, extra: true }] } } }));
        expect(fields.map((field) => field.path)).toEqual(['ord_ID', 'Customer', 'Customer.Name', 'lines', 'Customer.ID', 'extra']);
        expect(fields.find((field) => field.path === 'Customer.ID')).toMatchObject({ types: ['string'], present: 1, total: 2, cardinality: 'one' });
        expect(fields.find((field) => field.path === 'lines')).toMatchObject({ types: ['array'], cardinality: 'many' });
        expect(fields.some((field) => field.path === 'total')).toBe(false);
    });
    it('uses successful real responses only, never the generated schema', () => {
        const result = route({ last_test_status: 'http_401', last_test_payload: { data: { orders: [{ error: 'bad' }] } }, output_schema: { properties: { fake: {} } } });
        expect(listItemFieldNames(result)).toEqual([]);
        expect(listItemFieldNames(route({ last_test_status: 'ok' }))).toEqual([]);
    });
    it('supports top-level arrays and empty / missing / wrong paths', () => {
        expect(listItemFieldNames(route({ items_path: '', last_test_payload: [{ ID: 1 }, { Extra: 'x' }] }))).toEqual(['ID', 'Extra']);
        expect(listResponseItems(route({ last_test_payload: { data: { orders: [] } } }))).toEqual([]);
        expect(listResponseItems(route({ last_test_payload: { data: { orders: {} } } }))).toBeNull();
    });
    it('rejects collection or object cardinality, incompatible or mixed types', () => {
        const fields = listItemFields(route({ items_path: '', last_test_payload: [{ id: 1, lines: [], nested: { id: 'one' }, mixed: 1 }, { id: 2, mixed: 'two' }] }));
        expect(compatibleField(fields.find((field) => field.path === 'id')!, 'integer')).toBe(true);
        expect(compatibleField(fields.find((field) => field.path === 'id')!, 'number')).toBe(true);
        expect(compatibleField(fields.find((field) => field.path === 'id')!, 'string')).toBe(false);
        expect(compatibleField(fields.find((field) => field.path === 'lines')!, 'array')).toBe(false);
        expect(compatibleField(fields.find((field) => field.path === 'nested')!, 'string')).toBe(false);
        expect(compatibleField(fields.find((field) => field.path === 'mixed')!, 'integer')).toBe(false);
    });
    it('never offers literal dotted keys that the mapper cannot read', () => {
        expect(listItemFieldNames(route({ items_path: '', last_test_payload: [{ 'literal.dot': 'bad', safe: 'ok' }] }))).toEqual(['safe']);
    });
});

describe('configured LLM destinations', () => {
    it('keeps destination location and type while excluding fixed and secret parameters', () => {
        const params = [
            { name: 'ord_ID', location: 'query', source: 'llm', type: 'integer', required: true },
            { name: 'Authorization', location: 'header', source: 'secret', type: 'string' },
            { name: 'type', location: 'query', source: 'fixed', type: 'string' },
        ];
        const detail = route({ parameters: params as ApiRoute['parameters'] });
        expect(detailLlmParamNames(detail)).toEqual(['ord_ID']);
        expect(detailLlmParams(detail)[0]).toMatchObject({ name: 'ord_ID', location: 'query', type: 'integer', required: true });
    });
    it('prefers canonical config over a stale parameter resource', () => {
        expect(detailLlmParamNames(route({ config: { request: { params: [{ name: 'current', source: 'llm', type: 'string', location: 'path' }] } } as ApiRoute['config'], parameters: [{ name: 'old', source: 'llm' }] as ApiRoute['parameters'] }))).toEqual(['current']);
    });
});

describe('guided real-call argument types', () => {
    it('parses typed values and rejects invalid integer or boolean values', () => {
        expect(parseRelationArgument('42', 'integer')).toBe(42);
        expect(parseRelationArgument('false', 'boolean')).toBe(false);
        expect(parseRelationArgument('[1]', 'array')).toEqual([1]);
        expect(parseRelationArgument('2026-10-26', 'string')).toBe('2026-10-26');
        expect(() => parseRelationArgument('4.2', 'integer')).toThrow();
        expect(() => parseRelationArgument('1', 'boolean')).toThrow();
    });
});

describe('legacy schema helper', () => {
    it('walks nested schemas but is not used as response evidence', () => {
        expect(listItemSchema(route({ items_path: 'data', output_schema: { properties: { data: { type: 'array', items: { properties: { id: {} } } } } } }))).toEqual({ properties: { id: {} } });
        expect(listItemSchema(route({ endpoint_type: 'detail' }))).toBeNull();
    });
});
