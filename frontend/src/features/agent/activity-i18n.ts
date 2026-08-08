export type AgentUiMessages = {
    details: string;
    hideDetails: string;
    cancel: string;
    continue: string;
    calls: string;
    steps: string;
    remaining: string;
    completed: string;
    failed: string;
};

const messages: Record<'en' | 'it', AgentUiMessages> = {
    en: {
        details: 'Show details',
        hideDetails: 'Hide details',
        cancel: 'Cancel',
        continue: 'Continue search',
        calls: 'API requests',
        steps: 'Agent steps',
        remaining: 'remaining',
        completed: 'Completed',
        failed: 'Failed',
    },
    it: {
        details: 'Mostra dettagli',
        hideDetails: 'Nascondi dettagli',
        cancel: 'Annulla',
        continue: 'Continua la ricerca',
        calls: 'Richieste API',
        steps: 'Passaggi agentici',
        remaining: 'rimanenti',
        completed: 'Completato',
        failed: 'Errore',
    },
};

export function agentUiMessages(locale: string | null | undefined): AgentUiMessages {
    const language = locale?.trim().toLowerCase().split(/[-_]/)[0];
    return language === 'it' ? messages.it : messages.en;
}
