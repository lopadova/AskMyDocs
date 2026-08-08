import { describe, expect, it } from 'vitest';
import { agentUiMessages } from './activity-i18n';

describe('agent activity i18n', () => {
    it('selects Italian for full BCP-47 locales', () => {
        expect(agentUiMessages('it-IT').cancel).toBe('Annulla');
    });

    it('falls back to English for missing or unsupported locales', () => {
        expect(agentUiMessages(undefined).details).toBe('Show details');
        expect(agentUiMessages('fr-FR').details).toBe('Show details');
    });
});
