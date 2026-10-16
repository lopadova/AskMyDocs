import { useUiTranslation } from '@/lib/ui-locale';

const IT: Record<string, string> = {
    'Appearance': 'Aspetto',
    'Visual identity': 'Identità visiva',
    'Choose the platform’s visual identity.': 'Scegli l’identità visiva della piattaforma.',
    'One visual identity for every workspace, with coordinated day and night illustrations.': 'Una sola identità visiva per ogni spazio di lavoro, con illustrazioni coordinate di giorno e di notte.',
    'Active theme': 'Tema attivo',
    'Theme collection': 'Collezione temi',
    'Preview': 'Anteprima',
    'Workspace preview': 'Anteprima spazio di lavoro',
    'Typography, corners, spacing, colours, and illustration are included in this preview.': 'Questa anteprima include caratteri, angoli, spaziature, colori e illustrazioni.',
    'Day': 'Giorno',
    'Night': 'Notte',
    'Preview lighting': 'Illuminazione anteprima',
    'Preview only. Your selection becomes visible to everyone after you save.': 'Questa è solo un’anteprima. La scelta diventa visibile a tutti dopo il salvataggio.',
    'Save platform theme': 'Salva tema della piattaforma',
    'Keep active theme': 'Mantieni tema attivo',
    'Unsaved selection': 'Scelta da salvare',
    'Platform theme saved.': 'Tema della piattaforma salvato.',
    'Could not load the platform theme. Please try again.': 'Impossibile caricare il tema della piattaforma. Riprova.',
    'Could not save the platform theme. Your selection is still available.': 'Impossibile salvare il tema della piattaforma. La tua scelta è ancora disponibile.',
    'Loading the platform theme…': 'Caricamento del tema della piattaforma…',
    'Humanistic': 'Umanistico',
    'Tech PRO': 'Tech PRO',
    'Retro future': 'Futuro retrò anni 70',
    'Dynamic office': 'Dinamico office sportivo',
    'Luxury offices': 'Uffici di lusso',
    'Warm materials, calm spaces, and a human perspective.': 'Materiali caldi, spazi tranquilli e una prospettiva umana.',
    'Precise geometry, cool light, and a technology-led workspace.': 'Geometrie precise, luce fredda e un ambiente orientato alla tecnologia.',
    'Seventies optimism, sculptural forms, and warm colour.': 'Ottimismo anni Settanta, forme scultoree e colori caldi.',
    'Energetic spaces, bold accents, and a shared sense of momentum.': 'Spazi energici, accenti decisi e uno slancio condiviso.',
    'Refined offices, rich materials, and quiet architectural detail.': 'Uffici raffinati, materiali ricercati e dettagli architettonici discreti.',
};

/** Feature-local copy keeps this catalogue independent of the shared runtime. */
export function useAppearanceTranslation() {
    const { locale, t } = useUiTranslation();
    return (message: string): string => locale === 'it' ? (IT[message] ?? t(message)) : message;
}

export const THEME_COPY = {
    humanistic: { label: 'Humanistic', description: 'Warm materials, calm spaces, and a human perspective.' },
    'tech-pro': { label: 'Tech PRO', description: 'Precise geometry, cool light, and a technology-led workspace.' },
    'retro-future': { label: 'Retro future', description: 'Seventies optimism, sculptural forms, and warm colour.' },
    'dynamic-office': { label: 'Dynamic office', description: 'Energetic spaces, bold accents, and a shared sense of momentum.' },
    luxury: { label: 'Luxury offices', description: 'Refined offices, rich materials, and quiet architectural detail.' },
} as const;
