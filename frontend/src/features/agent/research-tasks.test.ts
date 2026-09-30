import { describe, expect, it } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { researchTasks } from './research-tasks';

function event(sequence: number, type: string, data: Record<string, unknown> = {}, run_id = 'r1'): AgentRunEvent {
    return { sequence, type, data, run_id, locale: 'it', phase: null, message_key: null, message_params: {},
        message: null, progress: null, can_cancel: true, created_at: null };
}
const plan = event(1, 'research.planned', { tasks: [
    { id: 0, question: 'Quali servizi offre HUB-MI-07?' },
    { id: 1, question: 'Qual è lo stato di RL-TRACK-9355?' },
] });

describe('research task activity', () => {
    it('tracks concurrent branches independently, including flow zero', () => {
        const tasks = researchTasks([plan,
            event(2, 'research.task', { research_flow_id: 0, task_status: 'documents' }),
            event(3, 'research.task', { research_flow_id: 1, task_status: 'researching' }),
            event(4, 'research.task', { research_flow_id: 0, task_status: 'collected' }),
        ]);
        expect(tasks.map((task) => task.status)).toEqual(['collected', 'researching']);
        expect(tasks.every((task) => !task.settled)).toBe(true);
    });

    it('waits for per-task validation and never turns every row green on global completion', () => {
        expect(researchTasks([plan, event(2, 'synthesis.started')]).map((task) => task.status)).toEqual(['verifying', 'verifying']);
        const tasks = researchTasks([plan, event(2, 'research.finished', { tasks: [
            { id: 0, task_status: 'unverified' }, { id: 1, task_status: 'answered' },
        ] }), event(3, 'run.partial')]);
        expect(tasks.map((task) => task.status)).toEqual(['unverified', 'answered']);
        expect(tasks.every((task) => task.settled)).toBe(true);
        expect(researchTasks([plan, event(2, 'run.completed')]).every((task) => task.status === 'unverified')).toBe(true);
    });

    it('handles replay, failure, cancellation, unknown payloads and a new turn', () => {
        const start = event(2, 'research.task', { research_flow_id: 0, task_status: 'researching' });
        expect(researchTasks([start, plan, start]).map((task) => task.status)).toEqual(['researching', 'pending']);
        expect(researchTasks([plan, event(2, 'research.planned', { tasks: [{ id: 1, question: '' }, null] })])).toHaveLength(2);
        expect(researchTasks([plan, event(2, 'research.task', { research_flow_id: 0, task_status: 'made_up' })])[0].status).toBe('pending');
        expect(researchTasks([plan, event(2, 'run.cancelled')]).every((task) => task.status === 'cancelled')).toBe(true);
        expect(researchTasks([plan, event(2, 'run.failed')]).every((task) => task.status === 'failed')).toBe(true);
        expect(researchTasks([plan, event(1, 'run.started', {}, 'r2')])).toEqual([]);
    });
});
