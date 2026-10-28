import type { AgentRunEvent } from '../../lib/agent-run-events';

export const findingEvent: AgentRunEvent = {
    run_id: 'run-1', sequence: 1, type: 'research.findings', phase: 'research', locale: 'it',
    message_key: null, message_params: {}, message: null, progress: null, can_cancel: true, created_at: null,
    data: { validation: { status: 'accepted' }, findings: [{ id: 'first', text: 'Il cliente segnala il barcode mancante il 3 luglio.',
        quote: 'Der Barcode fehlt für die Rücksendung.', citation: { document_id: 11, title: 'Email del 3 luglio, 13:52', source_path: null } }] },
};
