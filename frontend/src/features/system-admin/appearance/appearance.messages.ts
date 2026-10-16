import { useUiTranslation } from '@/lib/ui-locale';
import { VISUAL_THEMES, type VisualThemeId } from '@/lib/visual-theme';

const IT: Record<string, string> = {
    'Appearance': 'Aspetto',
    'Visual identity': 'Identità visiva',
    'Choose the platform’s visual identity.': 'Scegli l’identità visiva della piattaforma.',
    'One visual identity for every workspace, with coordinated day and night illustrations.': 'Una sola identità visiva per ogni spazio di lavoro, con illustrazioni coordinate di giorno e di notte.',
    'Active theme': 'Tema attivo',
    'Theme collection': 'Collezione temi',
    'Theme category': 'Categoria temi',
    'All': 'Tutti',
    'Original identities': 'Identità originali',
    'Worlds': 'Ambientazioni',
    'Abstract themes': 'Astratti',
    '{count} of {total} themes': '{count} temi su {total}',
    'Your selected preview is outside this category.': 'L’anteprima selezionata appartiene a un’altra categoria.',
    'Show selected theme': 'Mostra tema selezionato',
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
    'Cartographic atlas': 'Atlante cartografico',
    'Polar research': 'Ricerca polare',
    'Orbital archive': 'Archivio orbitale',
    'Botanical herbarium': 'Erbario botanico',
    'Ocean research': 'Ricerca oceanica',
    'City newsroom': 'Redazione urbana',
    'Archaeological traces': 'Tracce archeologiche',
    'Alpine retreat': 'Rifugio alpino',
    'Paper landscapes': 'Paesaggi di carta',
    'Blueprint workshop': 'Officina blueprint',
    'Semantic orbits': 'Orbite semantiche',
    'Prismatic index': 'Indice prismatico',
    'Ink flows': 'Flussi d’inchiostro',
    'Warm materials, calm spaces, and a human perspective.': 'Materiali caldi, spazi tranquilli e una prospettiva umana.',
    'Precise geometry, cool light, and a technology-led workspace.': 'Geometrie precise, luce fredda e un ambiente orientato alla tecnologia.',
    'Seventies optimism, sculptural forms, and warm colour.': 'Ottimismo anni Settanta, forme scultoree e colori caldi.',
    'Energetic spaces, bold accents, and a shared sense of momentum.': 'Spazi energici, accenti decisi e uno slancio condiviso.',
    'Refined offices, rich materials, and quiet architectural detail.': 'Uffici raffinati, materiali ricercati e dettagli architettonici discreti.',
    'Maps, ink, and layered charts in a cartographer’s studio.': 'Mappe, inchiostro e carte sovrapposte nello studio di un cartografo.',
    'Field notebooks and glacial light at a polar research camp.': 'Taccuini di ricerca e luce glaciale in un campo polare.',
    'Weightless records and Earth views in an orbital research station.': 'Documenti in assenza di gravità e vedute della Terra in una stazione di ricerca orbitale.',
    'Pressed specimens and research sheets in a botanical greenhouse.': 'Campioni essiccati e schede di ricerca in una serra botanica.',
    'Research logs and marine light in a submerged observatory.': 'Diari di ricerca e luce marina in un osservatorio sommerso.',
    'Clippings, contact sheets, and red ink in a city newsroom.': 'Ritagli, provini fotografici e inchiostro rosso in una redazione urbana.',
    'Excavation notes and desert light at an archaeological field camp.': 'Appunti di scavo e luce del deserto in un campo archeologico.',
    'Survey maps and warm timber in an alpine research retreat.': 'Carte di rilevamento e legno caldo in un rifugio di ricerca alpino.',
    'Folded records and paths of discovery in a sculpted paper world.': 'Documenti piegati e percorsi di scoperta in un mondo scolpito nella carta.',
    'Technical drawings and precise ink in an engineering workshop.': 'Disegni tecnici e tratti precisi in un’officina di progettazione.',
    'Translucent forms and orbital paths reveal connections between documents.': 'Forme traslucide e traiettorie orbitali rivelano i collegamenti tra i documenti.',
    'Glass prisms and layers of light turn research into a clear visual rhythm.': 'Prismi di vetro e strati di luce danno alla ricerca un ritmo visivo chiaro.',
    'Fluid ink and textured paper trace the flow from sources to insight.': 'Inchiostro fluido e carta materica tracciano il flusso dalle fonti alla comprensione.',
};

/** Feature-local copy keeps this catalogue independent of the shared runtime. */
export function useAppearanceTranslation() {
    const { locale, t } = useUiTranslation();
    return (message: string, values?: Record<string, string | number>): string => {
        const copy = locale === 'it' ? (IT[message] ?? t(message)) : message;
        return values ? copy.replace(/\{(\w+)\}/g, (token, key: string) => String(values[key] ?? token)) : copy;
    };
}

export const THEME_COPY = Object.fromEntries(VISUAL_THEMES.map(({ id, label, description }) => [id, { label, description }])) as Record<VisualThemeId, { label: string; description: string }>;
