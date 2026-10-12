import type { TriageRule } from './email-triage.api';

export const triageTypeLabels = { noul: 'Presente o assente', choice: 'Una categoria', score: 'Livelli ordinati' };

export function TriageRuleSummary({ rule }: { rule: TriageRule }) {
    return <div style={{ display: 'grid', gap: 8, minWidth: 0, fontSize: 12 }}>
        <p style={{ margin: 0, color: 'var(--fg-2)', overflowWrap: 'anywhere' }}>{rule.instructions}</p>
        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 5 }} aria-label={`Risultati di ${rule.label}`}>
            {rule.options.map((option) => <li key={option.key} style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'baseline', overflowWrap: 'anywhere' }}>
                <span>{option.label !== option.text && <strong>{option.label}: </strong>}{option.text}</span>
                {option.tag && <span style={{ color: 'var(--fg-1)', border: '1px solid var(--hairline)', borderRadius: 5, padding: '1px 5px' }}>Tag: {option.tag}</span>}
            </li>)}
        </ul>
    </div>;
}
