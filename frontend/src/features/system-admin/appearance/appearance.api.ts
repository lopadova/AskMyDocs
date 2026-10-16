import { api } from '@/lib/api';
import type { VisualThemeId } from '@/lib/visual-theme';

export type AppearanceSettings = { theme: VisualThemeId };
export const APPEARANCE_QUERY_KEY = ['system', 'appearance'] as const;

export const appearanceApi = {
    async load(): Promise<AppearanceSettings> {
        const { data } = await api.get<{ data: AppearanceSettings }>('/api/system/appearance');
        return data.data;
    },
    async save(theme: VisualThemeId): Promise<AppearanceSettings> {
        const { data } = await api.put<{ data: AppearanceSettings }>('/api/system/appearance', { theme });
        return data.data;
    },
};
