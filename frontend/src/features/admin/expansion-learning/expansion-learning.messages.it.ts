/**
 * Italian interface copy for the admin "Expansion learning" screen
 * (`ExpansionLearningView` + `ExpansionLearningTrainingForm`) and its nav
 * entry. English strings are the keys, spread into `IT_MESSAGES` (see
 * `lib/ui-messages.it.ts`).
 *
 * Server messages (422 `refused`, 403 `super_admin_required`, …) and server
 * data (project keys, model ids, few-shot rewrites) are never translated.
 */
export const EXPANSION_LEARNING_MESSAGES_IT: Record<string, string> = {
    // Navigation
    'Expansion learning': 'Apprendimento delle espansioni',
    'Learn from searches to improve query rewriting.': 'Impara dalle ricerche per migliorare la riscrittura delle domande.',

    // Page
    'Learn from past searches which query rewrites actually find new results. An analysis proposes new weights and examples; nothing changes until you apply it.':
        'Scopri dalle ricerche passate quali riscritture della domanda trovano davvero nuovi risultati. Un’analisi propone nuovi pesi ed esempi; nulla cambia finché non la applichi.',
    'Choose a project to analyze it.': 'Scegli un progetto per analizzarlo.',
    'Analyze now': 'Analizza ora',
    'Queueing…': 'Accodamento…',
    'Analysis queued.': 'Analisi messa in coda.',
    'Expansion learning is disabled on this deployment.': 'L’apprendimento delle espansioni è disattivato in questa installazione.',
    'Failed to load expansion learning.': 'Impossibile caricare l’apprendimento delle espansioni.',
    'No searches with query expansion have been recorded yet. Signals appear here once users search with expansion enabled.':
        'Non è ancora stata registrata alcuna ricerca con espansione della domanda. I segnali compaiono qui quando gli utenti cercano con l’espansione attiva.',

    // KPIs
    'Recorded searches': 'Ricerche registrate',
    'Last 30 days': 'Ultimi 30 giorni',
    'With feedback': 'Con feedback',
    'Auto-apply on': 'Applicazione automatica attiva',
    'Auto-apply off': 'Applicazione automatica disattiva',
    'Training consent: yes': 'Consenso all’addestramento: sì',
    'Training consent: no': 'Consenso all’addestramento: no',

    // Applied tuning
    'Applied tuning': 'Taratura applicata',
    'No tuning is applied: the expansion uses neutral weights.': 'Nessuna taratura applicata: l’espansione usa pesi neutri.',
    'Tuning': 'Taratura',
    'Weights': 'Pesi',
    'Few-shot examples': 'Esempi few-shot',
    'Decided': 'Deciso',
    'Benchmark gate': 'Verifica di benchmark',
    'Roll back': 'Annulla applicazione',
    'Roll back tuning #{id}': 'Annulla l’applicazione della taratura #{id}',
    'Confirm rolling back tuning #{id}': 'Conferma l’annullamento della taratura #{id}',
    'The expansion returns to neutral weights.': 'L’espansione torna ai pesi neutri.',
    'Tuning rolled back.': 'Taratura annullata.',

    // Proposals
    'Proposals': 'Proposte',
    'No proposals yet. Choose a project and click Analyze now.': 'Nessuna proposta. Scegli un progetto e fai clic su Analizza ora.',
    'ID': 'ID',
    'Details': 'Dettagli',
    'Hide details': 'Nascondi dettagli',
    'Evidence': 'Evidenze',
    'No examples.': 'Nessun esempio.',
    'Apply': 'Applica',
    'Reject': 'Rifiuta',
    'Apply tuning #{id}': 'Applica la taratura #{id}',
    'Reject tuning #{id}': 'Rifiuta la taratura #{id}',
    'Confirm applying tuning #{id}': 'Conferma l’applicazione della taratura #{id}',
    'Confirm rejecting tuning #{id}': 'Conferma il rifiuto della taratura #{id}',
    'Tuning applied.': 'Taratura applicata.',
    'Proposal rejected.': 'Proposta rifiutata.',

    // Gate / status labels
    'Passed': 'Superata',
    'Not measurable': 'Non misurabile',
    'Skipped': 'Saltata',
    'Proposed': 'Proposta',
    'Applied': 'Applicata',
    'Rejected': 'Rifiutata',
    'Superseded': 'Sostituita',
    'Pending': 'In attesa',
    'Submitted': 'Inviato',
    'Training': 'In addestramento',
    'Ready': 'Pronto',
    'Promoted': 'Promosso',
    'Retired': 'Ritirato',

    // Models
    'Trained models': 'Modelli addestrati',
    'No training runs yet.': 'Nessun addestramento avviato.',
    'Backend': 'Backend',
    'Base model': 'Modello di base',
    'Rows': 'Righe',
    'Model ID': 'ID modello',
    'Failure reason': 'Motivo dell’errore',
    'Retire': 'Ritira',
    'Refresh model #{id}': 'Aggiorna lo stato del modello #{id}',
    'Promote model #{id}': 'Promuovi il modello #{id}',
    'Retire model #{id}': 'Ritira il modello #{id}',
    'Confirm promoting model #{id}': 'Conferma la promozione del modello #{id}',
    'Confirm retiring model #{id}': 'Conferma il ritiro del modello #{id}',
    'Status refreshed.': 'Stato aggiornato.',
    'Model promoted.': 'Modello promosso.',
    'Model retired.': 'Modello ritirato.',

    // Training form
    'Start training': 'Avvia addestramento',
    'Starting…': 'Avvio…',
    'Uses the rewarded searches of every project. Super-admin only.': 'Usa le ricerche premiate di tutti i progetti. Solo super-admin.',
    'Uses the rewarded searches of project {project}. Super-admin only.': 'Usa le ricerche premiate del progetto {project}. Solo super-admin.',
    'Export dataset (JSONL)': 'Esporta il dataset (JSONL)',
    'OpenAI fine-tuning': 'Fine-tuning OpenAI',
    'This organisation has not consented to sending its masked queries to a training provider; the request will be refused.':
        'Questa organizzazione non ha acconsentito all’invio delle domande mascherate a un fornitore di addestramento; la richiesta verrà rifiutata.',
    'I understand this builds a dataset from recorded searches and, for OpenAI, sends it to the provider.':
        'Ho compreso che verrà creato un dataset dalle ricerche registrate e, per OpenAI, verrà inviato al fornitore.',
    'Confirm that you want to start a training run.': 'Conferma di voler avviare un addestramento.',
    'Training run started.': 'Addestramento avviato.',
};
