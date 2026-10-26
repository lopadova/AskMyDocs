export interface Schema {
    type?: string;
    description?: string;
    properties?: Record<string, Schema>;
    required?: string[];
    items?: Schema;
    example?: unknown;
    examples?: unknown[];
    [key: string]: unknown;
}
export interface Binding {
    source: 'input' | 'constant' | 'step' | 'item';
    path?: string;
    step?: string;
    value?: unknown;
    mode?: 'value' | 'index' | 'project';
    index?: number;
    project_path?: string;
}
export interface ToolStep {
    id: string;
    name: string;
    operation: 'api' | 'select' | 'filter' | 'sort' | 'limit' | 'compose' | 'join';
    route_id?: number;
    bindings: Record<string, Binding>;
    wait_for: string[];
    collection: { mode: 'single' | 'bounded'; max_pages?: number; max_items?: number };
    iterate?: { source: Binding; max_items: number; allow_partial: boolean };
    config?: Record<string, unknown>;
    output_schema?: Schema;
}
export interface ToolTest {
    name: string;
    mode: 'simulation' | 'live';
    inputs: Record<string, unknown>;
    fixtures: Record<string, unknown>;
    assertions: { path: string; operator: 'exists' | 'type' | 'equals' | 'count' | 'min_count' | 'max_count'; value?: unknown }[];
}
export interface ToolDefinition {
    version: 1;
    input_schema: Schema;
    steps: ToolStep[];
    output: Record<string, Binding>;
    tests: ToolTest[];
    limits: { physical_requests: number; timeout_seconds: number };
}
export interface ApiTool {
    id: number;
    name: string;
    description: string;
    project_key: string;
    status: 'draft' | 'active' | 'deprecated' | 'disabled_errors' | 'disabled_manual';
    failure_threshold: number;
    definition: ToolDefinition;
}
export interface Endpoint {
    id: number;
    name: string;
    connector_id: number;
    connector_name: string;
    method: string;
    input_schema?: Schema;
    output_schema?: Schema;
    parameters?: { name: string; type: string; required: boolean; source?: string; location?: string }[];
    example?: unknown;
}
export type ToolPayload = Pick<ApiTool, 'name' | 'description' | 'project_key' | 'failure_threshold' | 'definition'>;
export interface ToolDetail { tool: ApiTool; revisions: unknown[]; runs: Record<string, unknown>[]; verifications: unknown[]; endpoints: Endpoint[] }
export interface ToolIndex { enabled: boolean; tools: ApiTool[]; endpoints: Endpoint[] }
export interface StepRun {
    step_id: string;
    status: string;
    diagnostics?: { inputs?: Record<string, unknown>; origins?: Record<string, unknown>; output?: unknown; error?: unknown };
    [key: string]: unknown;
}
