import { api } from '../../lib/api';

/**
 * v8.0.1 / deep-review F5 — per-user chat preferences API.
 *
 * Replaces the previous browser-local `localStorage` toggle for the
 * counterfactual panel so the choice survives multi-device use,
 * fresh sessions, and cache wipes. The BE owns persistence and
 * returns the merged (defaults + stored) view so the UI never has
 * to reason about missing keys.
 */

export interface ChatPreferences {
    counterfactual_enabled: boolean;
    [key: string]: unknown;
}

export interface ChatPreferencesResponse {
    preferences: ChatPreferences;
    defaults: ChatPreferences;
}

export const chatPreferencesApi = {
    async load(): Promise<ChatPreferencesResponse> {
        const { data } = await api.get<ChatPreferencesResponse>('/api/me/chat-preferences');
        return data;
    },

    /**
     * Patch one or more preference keys. Use `null` to delete a key
     * (the BE then returns the default value for it). Boolean values
     * are serialised as `'0'`/`'1'` because the BE's custom validator
     * accepts BOTH native JSON booleans AND their string equivalents
     * (`'0'`, `'1'`, `'true'`, `'false'`); strings keep the wire
     * payload small and JSON-safe across non-strict clients.
     */
    async save(patch: Partial<Record<keyof ChatPreferences, boolean | null>>): Promise<ChatPreferencesResponse> {
        const body: Record<string, string | null> = {};
        for (const [key, value] of Object.entries(patch)) {
            // Partial<...> means the type still admits `undefined`
            // values (esp. without `exactOptionalPropertyTypes`).
            // Treat undefined as "no change" — skipping the key
            // entirely — so callers passing
            // `{ counterfactual_enabled: undefined }` don't
            // unintentionally flip the setting to false. Use null
            // explicitly to request deletion of a key.
            if (value === undefined) {
                continue;
            }
            if (value === null) {
                body[key] = null;
                continue;
            }
            body[key] = value ? '1' : '0';
        }
        const { data } = await api.patch<ChatPreferencesResponse>('/api/me/chat-preferences', {
            preferences: body,
        });
        return data;
    },
};

export const CHAT_PREFERENCES_QUERY_KEY = ['me', 'chat-preferences'] as const;
