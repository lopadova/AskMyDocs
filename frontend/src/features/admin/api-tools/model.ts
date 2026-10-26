import type { Binding, Endpoint, Schema, ToolDefinition, ToolPayload, ToolStep } from './types';
export interface FieldPath { path: string; type: string; description?: string; example?: unknown; required: boolean; collectionPath?: string; projectPath?: string; schema?: Schema }
export function schemaPaths(schema?: Schema, example?: unknown, prefix = '', required = false): FieldPath[] {
    if (!schema && example === undefined) return [];
    const type = schema?.type ?? (Array.isArray(example) ? 'array' : example !== null && typeof example === 'object' ? 'object' : example === undefined ? 'unknown' : typeof example === 'number' && Number.isInteger(example) ? 'integer' : typeof example);
    const sample = schema?.example ?? schema?.examples?.[0] ?? example;
    const own: FieldPath[] = prefix ? [{ path: prefix, type, description: schema?.description, example: sample, required, schema: { ...schema, type } }] : [];
    if (type === 'array') {
        const itemExample = Array.isArray(sample) ? sample[0] : undefined;
        return [...own, ...schemaPaths(schema?.items, itemExample).filter((field) => field.collectionPath === undefined).map((field) => ({ ...field, path: `${prefix}[]${field.path ? `.${field.path}` : ''}`, collectionPath: prefix, projectPath: field.path }))];
    }
    const properties = schema?.properties ?? (sample !== null && typeof sample === 'object' && !Array.isArray(sample) ? Object.fromEntries(Object.keys(sample).map((key) => [key, {}])) : {});
    return [...own, ...Object.entries(properties).flatMap(([key, value]) => schemaPaths(value, sample !== null && typeof sample === 'object' ? (sample as Record<string, unknown>)[key] : undefined, prefix ? `${prefix}.${key}` : key, (prefix ? required : true) && (schema?.required ?? []).includes(key)))];
}
export function endpointFields(endpoint?: Endpoint): FieldPath[] {
    if (!endpoint) return [];
    const privateNames = new Set((endpoint.parameters ?? []).filter((parameter) => parameter.source === 'secret' || parameter.source === 'fixed').map((parameter) => parameter.name));
    const fields: FieldPath[] = Object.entries(endpoint.input_schema?.properties ?? {}).filter(([name]) => !privateNames.has(name)).map(([path, schema]) => ({ path, type: schema.type ?? 'object', description: schema.description, example: schema.example, schema, required: (endpoint.input_schema?.required ?? []).includes(path) }));
    const parameters = (endpoint.parameters ?? []).filter((p) => p.source !== 'secret' && p.source !== 'fixed');
    for (const parameter of parameters) {
        const field = fields.find((candidate) => candidate.path === parameter.name);
        if (field) { field.required ||= parameter.required; if (!field.schema?.type) { field.type = parameter.type; field.schema = { ...field.schema, type: parameter.type }; } }
        else fields.push({ path: parameter.name, type: parameter.type, schema: { type: parameter.type }, required: parameter.required });
    }
    return fields;
}
export function fieldBinding(source: 'input' | 'step', field: FieldPath, step?: string): Binding {
    return { source, ...(step ? { step } : {}), path: field.collectionPath ?? field.path, mode: field.collectionPath !== undefined ? 'project' : 'value', ...(field.collectionPath !== undefined ? { project_path: field.projectPath } : {}) };
}
export const blankDefinition = (): ToolDefinition => ({ version: 1, input_schema: { type: 'object', properties: {}, required: [] }, steps: [], output: {}, tests: [], limits: { physical_requests: 30, timeout_seconds: 120 } });
export const blankTool = (): ToolPayload => ({ name: '', description: '', project_key: '', failure_threshold: 3, definition: blankDefinition() });
export const newStep = (steps: ToolStep[], reservedIds: string[] = []): ToolStep => {
    const occupied = new Set([...steps.map((step) => step.id), ...reservedIds, ...steps.flatMap((step) => [...step.wait_for, ...(step.iterate?.source.source === 'step' && step.iterate.source.step ? [step.iterate.source.step] : []), ...Object.values(step.bindings).flatMap((binding) => binding.source === 'step' && binding.step ? [binding.step] : [])])]);
    let suffix = steps.length + 1;
    while (occupied.has(`step_${suffix}`)) suffix++;
    return { id: `step_${suffix}`, name: `Chiamata ${suffix}`, operation: 'api', bindings: {}, wait_for: [], collection: { mode: 'single' } };
};
export function bindingLabel(binding?: Binding): string {
    if (!binding) return 'Seleziona una sorgente';
    if (binding.source === 'constant') return `Costante: ${JSON.stringify(binding.value)}`;
    return `${binding.source === 'input' ? 'Input' : binding.source === 'item' ? 'Elemento iterazione' : binding.step ?? 'Step'}.${binding.path ?? '(risultato)'}${binding.mode === 'index' ? `[${binding.index ?? '?'}]${binding.project_path ? `.${binding.project_path}` : ''}` : binding.mode === 'project' ? `[].${binding.project_path ?? ''}` : ''}`;
}
export function removeStep(definition: ToolDefinition, id: string): ToolDefinition {
    // Do not silently discard dependent mappings: validation should report them.
    return { ...definition, steps: definition.steps.filter((step) => step.id !== id) };
}

/** Match known source shapes before offering a destination mapping. */
export function schemaCompatible(target: Schema | undefined, source: Schema | undefined): boolean {
    if (!target?.type || target.type === 'any') return true;
    if (!source?.type || source.type === 'unknown') return false;
    if (target.type === 'number' && source.type === 'integer') return true;
    if (target.type !== source.type) return false;
    if (target.type === 'array') return !target.items?.type || schemaCompatible(target.items, source.items);
    if (target.type === 'object') return (target.required ?? []).every((key) => !!source.properties?.[key] && schemaCompatible(target.properties?.[key], source.properties[key]))
        && Object.entries(target.properties ?? {}).every(([key, schema]) => !source.properties?.[key] || schemaCompatible(schema, source.properties[key]));
    return true;
}
export function schemaAtPath(schema: Schema | undefined, path = ''): Schema | undefined {
    if (!path) return schema;
    return path.split('.').reduce<Schema | undefined>((current, key) => current?.properties?.[key], schema);
}
export function stepOutput(step: ToolStep, endpoints: Endpoint[]): { schema: Schema; example: unknown } {
    const endpoint = endpoints.find((candidate) => candidate.id === step.route_id);
    let schema = step.operation === 'api' ? endpoint?.output_schema ?? {} : step.output_schema ?? {};
    let example = endpoint?.example;
    if (step.collection.mode === 'bounded') { schema = { type: 'array', items: {} }; example = undefined; }
    if (step.iterate) {
        schema = { type: 'array', items: schema }; example = example === undefined ? undefined : [example];
        if (step.iterate.allow_partial) { schema = { type: 'object', properties: { items: schema, errors: { type: 'array', items: { type: 'object' } }, _partial: { type: 'boolean' } }, required: ['items', 'errors', '_partial'] }; example = { items: example ?? [], errors: [], _partial: false }; }
    }
    return { schema, example };
}
export function bindingSourceSchema(binding: Binding, input: Schema, steps: ToolStep[], endpoints: Endpoint[], item?: Schema): Schema | undefined {
    const root = binding.source === 'input' ? input : binding.source === 'step' ? stepOutput(steps.find((step) => step.id === binding.step) ?? { operation: 'compose', collection: { mode: 'single' } } as ToolStep, endpoints).schema : binding.source === 'item' ? item : undefined;
    return schemaAtPath(root, binding.path);
}
export function bindingValueSchema(binding: Binding, input: Schema, steps: ToolStep[], endpoints: Endpoint[], item?: Schema): Schema | undefined {
    const schema = bindingSourceSchema(binding, input, steps, endpoints, item);
    if (binding.mode === 'index') return schemaAtPath(schema?.items, binding.project_path);
    if (binding.mode === 'project') return { type: 'array', items: schemaAtPath(schema?.items, binding.project_path) };
    return schema;
}
export function compatibleFieldBinding(source: 'input' | 'step' | 'item', field: FieldPath, target?: Schema, step?: string): Binding | undefined {
    const binding: Binding = { ...fieldBinding(source === 'item' ? 'input' : source, field, step), source };
    if (!target?.type) return binding;
    const projected = field.collectionPath !== undefined;
    if (projected) {
        if (target.type === 'array' && schemaCompatible(target.items, field.schema)) return binding;
        if (schemaCompatible(target, field.schema)) return { ...binding, mode: 'index' };
        return undefined;
    }
    if (schemaCompatible(target, field.schema)) return binding;
    if (field.schema?.type === 'array' && schemaCompatible(target, field.schema.items)) return { ...binding, mode: 'index', project_path: '' };
    return undefined;
}

export function stepDependencies(step: ToolStep): string[] {
    return [...new Set([...step.wait_for, ...(step.iterate?.source.source === 'step' && step.iterate.source.step ? [step.iterate.source.step] : []), ...Object.values(step.bindings).filter((binding) => binding.source === 'step').map((binding) => binding.step), ...(step.iterate?.source.source === 'step' ? [step.iterate.source.step] : [])].filter((id): id is string => !!id))];
}
