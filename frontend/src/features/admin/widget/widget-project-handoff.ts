import type { WidgetSetupSummary } from './widget-project-setup.api';

export function widgetProjectHandoff(setup: WidgetSetupSummary, locale: string): string {
    const italian = locale === 'it';
    const access = setup.user_auth_enabled
        ? (italian ? 'utenti autenticati nel gestionale' : 'users signed in to the host application')
        : (italian ? 'visitatori del sito pubblico' : 'public website visitors');

    if (italian) {
        return `# Documentazione per il widget ${setup.name}

- Progetto AskMyDocs: ${setup.project_key}
- Pubblico: ${setup.audience} (${access})
- Lingua dei documenti: ${setup.language}

## Argomenti da coprire

${setup.topics}

## Documenti da consegnare

Verificate il comportamento nella versione effettivamente usata, consultando l'interfaccia, il codice, i test e la documentazione approvata. Preparate un file Markdown UTF-8 per argomento, con un titolo chiaro e un nome di file stabile. Per ogni procedura indicate destinatari, prerequisiti, passaggi con le etichette reali dell'interfaccia, risultato atteso e soluzione degli errori comuni.

Usate esempi fittizi e contenuti condivisibili con tutto il pubblico indicato. Tenete fuori dai documenti dati personali, credenziali, note riservate e informazioni ancora da verificare. Per gli aggiornamenti conservate nome e percorso dei file.

## Materiale per il collaudo

Consegnate a parte le lacune da chiarire, almeno tre domande con risposta attesa e fonte, e una domanda fuori ambito per verificare come il widget segnala l'assenza di una risposta documentata. L'operatore caricherà nel progetto ${setup.project_key} i Markdown approvati.
`;
    }

    return `# Documentation for the ${setup.name} widget

- AskMyDocs project: ${setup.project_key}
- Audience: ${setup.audience} (${access})
- Document language: ${setup.language}

## Topics to cover

${setup.topics}

## Documents to deliver

Verify behavior in the version people use against the interface, source code, tests, and approved documentation. Write one UTF-8 Markdown file per topic, with a descriptive title and a stable filename. For each procedure, cover the intended users, prerequisites, steps using the exact interface labels, expected result, and common errors and remedies.

Use fictional examples and content suitable for the entire intended audience. Leave personal data, credentials, confidential notes, and unverified details out of the documents. Preserve the filenames and paths when making updates.

## Acceptance questions

Deliver open questions separately, plus at least three sample questions with an expected answer and source, and one out-of-scope question to verify how the widget reports missing documentation. The operator will upload the approved Markdown files to ${setup.project_key}.
`;
}
