/**
 * Italian interface copy for the admin "Path contexts" screen
 * (`PathContextsView` + `PathContextForm`). English strings are the keys,
 * spread into `IT_MESSAGES` (see `lib/ui-messages.it.ts`).
 *
 * The client-side validation messages from `validatePathContext()` are
 * listed with their limits spelled out (120 / 300 / 500); the Vitest suite
 * asserts every message the validator can produce has an entry here, so a
 * changed limit cannot silently fall back to English. Server messages are
 * never translated here.
 */
export const PATH_CONTEXTS_MESSAGES_IT: Record<string, string> = {
    'Path contexts': 'Contesti dei percorsi',
    '{count} total': '{count} in totale',
    'Project': 'Progetto',
    'All projects': 'Tutti i progetti',
    '+ Add path context': '+ Aggiungi contesto',
    'Describe what a part of the corpus is. When an answer cites a document under a prefix, the matching descriptions are given to the model as context (treated as untrusted data).':
        'Descrivi cosa contiene una parte del corpus. Quando una risposta cita un documento sotto un prefisso, le descrizioni corrispondenti vengono fornite al modello come contesto (trattate come dati non attendibili).',
    'Path contexts are disabled on this deployment.': 'I contesti dei percorsi sono disattivati in questa installazione.',
    'Failed to load path contexts.': 'Impossibile caricare i contesti dei percorsi.',
    'No path contexts yet. Click': 'Nessun contesto ancora definito. Fai clic su',
    'to describe a part of the corpus.': 'per descrivere una parte del corpus.',
    'Prefix': 'Prefisso',
    'Description': 'Descrizione',
    'Updated': 'Aggiornato',
    'Actions': 'Azioni',
    'Edit path context {prefix} in {project}': 'Modifica il contesto {prefix} in {project}',
    'Delete path context {prefix} in {project}': 'Elimina il contesto {prefix} in {project}',
    'Confirm deleting path context {prefix} in {project}': 'Conferma l’eliminazione del contesto {prefix} in {project}',
    'Path context saved.': 'Contesto salvato.',
    'Path context updated.': 'Contesto aggiornato.',
    'Path context deleted.': 'Contesto eliminato.',

    // Form
    'Add path context': 'Aggiungi contesto di percorso',
    'Edit path context: {prefix}': 'Modifica contesto di percorso: {prefix}',
    'Select a project…': 'Seleziona un progetto…',
    'Path prefix': 'Prefisso del percorso',
    'Project and prefix identify the context and cannot be changed. Delete and re-add to move it.':
        'Progetto e prefisso identificano il contesto e non si possono modificare. Per spostarlo, eliminalo e aggiungilo di nuovo.',
    'Relative to the project root, no leading slash, up to {max} characters.':
        'Relativo alla radice del progetto, senza barra iniziale, fino a {max} caratteri.',
    'What this part of the corpus is, e.g. current HR policies approved by the DPO.':
        'Cosa contiene questa parte del corpus, ad es. le policy HR in vigore approvate dal DPO.',
    'Saving…': 'Salvataggio…',

    // Client-side validation (mirror of the backend rules)
    'Select a project.': 'Seleziona un progetto.',
    'The project key may not be longer than 120 characters.': 'La chiave del progetto non può superare 120 caratteri.',
    'The path prefix is required.': 'Il prefisso del percorso è obbligatorio.',
    'The path prefix may not be longer than 300 characters.': 'Il prefisso del percorso non può superare 300 caratteri.',
    'The path prefix must not start with a slash.': 'Il prefisso del percorso non deve iniziare con una barra (/).',
    'The path prefix must not contain "..".': 'Il prefisso del percorso non deve contenere "..".',
    'The description is required.': 'La descrizione è obbligatoria.',
    'The description may not be longer than 500 characters.': 'La descrizione non può superare 500 caratteri.',
};
