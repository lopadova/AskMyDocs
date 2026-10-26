import { describe, expect, it } from 'vitest';
import { blankDefinition, compatibleFieldBinding, endpointFields, fieldBinding, newStep, schemaCompatible, schemaPaths, stepOutput } from './model';
import type { Endpoint } from './types';
export const endpoint: Endpoint = { id: 10, name: 'Ordini', connector_id: 2, connector_name: 'Gestionale', method: 'GET', input_schema: { type: 'object', properties: { customer: { type: 'object', properties: { id: { type: 'integer' } }, required: ['id'] }, page: { type: 'integer' } }, required: ['customer'] }, output_schema: { type: 'object', properties: { data: { type: 'object', properties: { orders: { type: 'array', items: { type: 'object', properties: { id: { type: 'integer' }, total: { type: 'number' } } } } } } } }, example: { data: { orders: [{ id: 42, total: 100 }] } } };
describe('composed tool schema mapping', () => {
    it('preserves nested paths, required input leaves and example values', () => {
        expect(endpointFields(endpoint)).toContainEqual(expect.objectContaining({ path: 'customer', type: 'object', required: true }));
        expect(endpointFields(endpoint)).toContainEqual(expect.objectContaining({ path: 'page', required: false }));
        expect(schemaPaths(endpoint.output_schema, endpoint.example)).toContainEqual(expect.objectContaining({ path: 'data.orders[].id', collectionPath: 'data.orders', projectPath: 'id', type: 'integer', example: 42 }));
    });
    it('projects every array item explicitly and never silently selects its first element', () => {
        const field = schemaPaths(endpoint.output_schema, endpoint.example).find((candidate) => candidate.path === 'data.orders[].id')!;
        const binding = fieldBinding('step', field, 'orders');
        expect(binding).toEqual({ source: 'step', step: 'orders', path: 'data.orders', mode: 'project', project_path: 'id' });
        expect(binding).not.toHaveProperty('index');
    });
    it('preserves array values and creates unique stable step ids after a deletion', () => {
        const field = schemaPaths(endpoint.output_schema, endpoint.example).find((candidate) => candidate.path === 'data.orders')!;
        expect(fieldBinding('step', field, 'orders')).toEqual({ source: 'step', step: 'orders', path: 'data.orders', mode: 'value' });
        const first = newStep([]); const third = { ...newStep([first]), id: 'step_3' };
        expect(newStep([first, third]).id).toBe('step_4');
        expect(blankDefinition().output).toEqual({});
    });
});

it('restricts mappings to the endpoint target type and leaves scalar indexes unspecified', () => {
    const id = schemaPaths(endpoint.output_schema, endpoint.example).find((field) => field.path === 'data.orders[].id')!;
    expect(compatibleFieldBinding('step', id, { type: 'integer' }, 'orders')).toEqual({ source: 'step', step: 'orders', path: 'data.orders', project_path: 'id', mode: 'index' });
    expect(compatibleFieldBinding('step', id, { type: 'array', items: { type: 'integer' } }, 'orders')?.mode).toBe('project');
    expect(compatibleFieldBinding('step', id, { type: 'object' }, 'orders')).toBeUndefined();
    expect(schemaCompatible({ type: 'object', properties: { id: { type: 'integer' } }, required: ['id'] }, { type: 'object', properties: { label: { type: 'string' } } })).toBe(false);
});
it('reflects iteration outputs and always wraps partial results in the stable envelope', () => {
    const step = { ...newStep([]), route_id: 10, iterate: { source: { source: 'input' as const, path: 'customers' }, max_items: 20, allow_partial: false } };
    expect(stepOutput(step, [endpoint]).schema).toEqual({ type: 'array', items: endpoint.output_schema });
    const output = stepOutput({ ...step, iterate: { ...step.iterate, allow_partial: true } }, [endpoint]);
    expect(output.schema).toMatchObject({ type: 'object', required: ['items', 'errors', '_partial'], properties: { items: { type: 'array', items: endpoint.output_schema }, errors: { type: 'array' }, _partial: { type: 'boolean' } } });
    expect(schemaPaths(output.schema, output.example)).toContainEqual(expect.objectContaining({ path: 'items', type: 'array' }));
});

it('does not reuse a deleted step identity still referenced by public output', () => {
    expect(newStep([], ['step_1']).id).toBe('step_2');
});
