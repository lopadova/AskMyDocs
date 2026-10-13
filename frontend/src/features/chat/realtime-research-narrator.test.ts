import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import type { AgentTurnStarted } from './chat.api';
import { RealtimeResearchNarrator, VOICE_PROGRESS_FIRST_DELAY_MS, VOICE_PROGRESS_INTERVAL_MS } from './realtime-research-narrator';

const run = { run_id: 'voice-run', locale: 'it', status: 'running' } as AgentTurnStarted;
function event(sequence: number, type: string, data: Record<string, unknown> = {}, params: AgentRunEvent['message_params'] = {}): AgentRunEvent {
    return { run_id: run.run_id, sequence, type, data, locale: 'it', message_params: params,
        phase: null, message_key: null, message: null, progress: null, can_cancel: true, created_at: null };
}
function fixture() {
    const speak = vi.fn().mockResolvedValue(undefined);
    const narrator = new RealtimeResearchNarrator(speak, () => 0);
    narrator.begin('it');
    narrator.bindRun(run);
    return { narrator, speak };
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => { vi.clearAllTimers(); vi.useRealTimers(); });

describe('RealtimeResearchNarrator', () => {
    it('does not announce a search before the server confirms that it actually started', async () => {
        const speak = vi.fn().mockResolvedValue(undefined);
        const narrator = new RealtimeResearchNarrator(speak);
        narrator.begin('it');
        await vi.advanceTimersByTimeAsync(30_000);
        expect(speak).not.toHaveBeenCalled();
        narrator.bindRun(run);
        await vi.advanceTimersByTimeAsync(1_000);
        expect(speak).toHaveBeenCalledOnce();
        narrator.stop();
    });

    it('leaves short searches quiet and spaces varied, phase-appropriate notices with occasional humor', async () => {
        const { narrator, speak } = fixture();
        narrator.observe([event(1, 'retrieval.started')]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_FIRST_DELAY_MS - 1);
        expect(speak).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(speak).toHaveBeenLastCalledWith('Vediamo un po’… sto cercando nei documenti.');
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_INTERVAL_MS - 1);
        expect(speak).toHaveBeenCalledOnce();
        await vi.advanceTimersByTimeAsync(1);
        expect(speak).toHaveBeenLastCalledWith('Mmm… sto passando in rassegna le fonti.');
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_INTERVAL_MS);
        expect(speak).toHaveBeenLastCalledWith('Sono qui, eh… non sono andato a prendere un caffè.');
    });

    it('reports real source counts without reading source contents or claiming a final answer', async () => {
        const { narrator, speak } = fixture();
        narrator.observe([event(1, 'retrieval.completed', { sources: 2, kb_debug: { text: 'PRIVATE DOCUMENT' } }, { count: 2 }),
            event(2, 'plan.created'), event(3, 'tool.started', { result: 'UNVERIFIED RESULT' }, { tool: 'Ordini' })]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_FIRST_DELAY_MS);
        expect(speak).toHaveBeenLastCalledWith('Questa ricerca ha trovato 2 fonti documentali pertinenti. Sto proseguendo con i controlli.');
        expect(JSON.stringify(speak.mock.calls)).not.toMatch(/PRIVATE DOCUMENT|UNVERIFIED RESULT/);
        narrator.observe([event(1, 'retrieval.completed', {}, { count: 2 })]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_INTERVAL_MS);
        expect(speak).toHaveBeenLastCalledWith('Mmm… un attimo, sto ricevendo i dati.');
    });

    it('reports an empty result honestly and rejects malformed counts', async () => {
        const { narrator, speak } = fixture();
        narrator.observe([event(1, 'retrieval.completed', {}, { count: 0 })]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_FIRST_DELAY_MS);
        expect(speak).toHaveBeenLastCalledWith('Questa ricerca non ha trovato fonti documentali pertinenti. Sto proseguendo con i controlli.');
        narrator.observe([event(2, 'retrieval.completed', {}, { count: '99' })]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_INTERVAL_MS);
        expect(speak.mock.calls.at(-1)?.[0]).not.toContain('99');
    });

    it('describes the actual research question and retires obsolete data requests', async () => {
        const { narrator, speak } = fixture();
        narrator.observe([event(1, 'research.planned', { tasks: [{ id: 0, question: 'Quali sono gli ordini?' }] }),
            event(2, 'research.task', { research_flow_id: 0, task_status: 'documents' })]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_FIRST_DELAY_MS);
        expect(speak).toHaveBeenLastCalledWith('Sto approfondendo questo punto della tua richiesta: Quali sono gli ordini?');
        narrator.observe([event(3, 'tool.started', {}, { tool: 'Ordini' }), event(4, 'tool.completed')]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_INTERVAL_MS);
        expect(speak.mock.calls.at(-1)?.[0]).not.toContain('chiedendo i dati');
        narrator.observe([event(5, 'synthesis.started')]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_INTERVAL_MS);
        expect(speak).toHaveBeenLastCalledWith('Sto mettendo insieme le informazioni raccolte e controllando la risposta.');
    });

    it('waits for a quiet gap after either participant speaks', async () => {
        const { narrator, speak } = fixture();
        await vi.advanceTimersByTimeAsync(7_000);
        narrator.pauseForSpeech();
        await vi.advanceTimersByTimeAsync(5_999);
        expect(speak).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(speak).toHaveBeenCalledOnce();
    });

    it.each(['run.completed', 'run.partial', 'run.cancelled', 'run.failed', 'run.awaiting_confirmation'])('stops on %s of the parent, ignoring replay, other runs, and branch completion', async (type) => {
        const { narrator, speak } = fixture();
        narrator.observe([{ ...event(99, 'run.completed'), run_id: 'old-run' }, event(1, 'run.completed', { research_flow_id: 0 })]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_FIRST_DELAY_MS);
        expect(speak).toHaveBeenCalledOnce();
        narrator.observe([event(2, type)]);
        await vi.advanceTimersByTimeAsync(60_000);
        expect(speak).toHaveBeenCalledOnce();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('resets notices between requests and stays stopped for a terminal receipt', async () => {
        const { narrator, speak } = fixture();
        narrator.observe([event(1, 'retrieval.completed', {}, { count: 8 })]);
        narrator.begin('en');
        narrator.bindRun({ ...run, run_id: 'next-run', locale: 'en' });
        narrator.observe([event(2, 'synthesis.started')]);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_FIRST_DELAY_MS);
        expect(speak).toHaveBeenLastCalledWith('One moment, I’m still here.');
        narrator.begin('it');
        narrator.bindRun({ ...run, status: 'completed' });
        await vi.advanceTimersByTimeAsync(60_000);
        expect(speak).toHaveBeenCalledOnce();
    });

    it('keeps delivery failures separate from the canonical turn', async () => {
        const speak = vi.fn().mockRejectedValue(new Error('Channel closed'));
        const narrator = new RealtimeResearchNarrator(speak);
        narrator.begin('it');
        narrator.bindRun(run);
        await vi.advanceTimersByTimeAsync(VOICE_PROGRESS_FIRST_DELAY_MS);
        narrator.stop();
        await vi.advanceTimersByTimeAsync(60_000);
        expect(speak).toHaveBeenCalledOnce();
        expect(vi.getTimerCount()).toBe(0);
    });
});
