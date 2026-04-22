// Shared data — realistic sample corpus
const PROJECTS = [
  { key: 'hr-portal',    label: 'HR Portal',    color: '#8b5cf6', docs: 428, members: 18 },
  { key: 'legal-vault',  label: 'Legal Vault',  color: '#22d3ee', docs: 312, members: 11 },
  { key: 'finance-ops',  label: 'Finance Ops',  color: '#f97316', docs: 197, members: 9 },
  { key: 'engineering',  label: 'Engineering',  color: '#a3e635', docs: 654, members: 34 },
];

const USERS = [
  { id: 1, name: 'Elena Ricci',       email: 'elena.ricci@acme.io',    role: 'super-admin', projects: ['hr-portal','legal-vault','finance-ops','engineering'], active: true,  last: '2m ago',  avatar: 'ER', color: '#8b5cf6' },
  { id: 2, name: 'Marco Bianchi',     email: 'marco@acme.io',          role: 'admin',       projects: ['hr-portal','legal-vault'],       active: true,  last: '14m ago', avatar: 'MB', color: '#22d3ee' },
  { id: 3, name: 'Sara Colombo',      email: 's.colombo@acme.io',      role: 'editor',      projects: ['hr-portal'],                      active: true,  last: '1h ago',  avatar: 'SC', color: '#f97316' },
  { id: 4, name: 'Giovanni De Luca',  email: 'g.deluca@acme.io',       role: 'editor',      projects: ['legal-vault','finance-ops'],      active: true,  last: '3h ago',  avatar: 'GD', color: '#a3e635' },
  { id: 5, name: 'Anna Moretti',      email: 'anna.m@acme.io',         role: 'viewer',      projects: ['engineering'],                    active: true,  last: 'today',   avatar: 'AM', color: '#e11d48' },
  { id: 6, name: 'Luca Ferrari',      email: 'luca@acme.io',           role: 'editor',      projects: ['engineering','finance-ops'],      active: false, last: '6d ago',  avatar: 'LF', color: '#0ea5e9' },
  { id: 7, name: 'Chiara Rossi',      email: 'chiara.r@acme.io',       role: 'viewer',      projects: ['hr-portal'],                      active: true,  last: 'today',   avatar: 'CR', color: '#eab308' },
  { id: 8, name: 'Tommaso Greco',     email: 't.greco@acme.io',        role: 'admin',       projects: ['engineering'],                    active: true,  last: '22m ago', avatar: 'TG', color: '#14b8a6' },
];

const KB_TREE = {
  'hr-portal': [
    { type: 'folder', name: 'onboarding', children: [
      { type: 'doc', name: 'new-hire-checklist.md',         kind: 'runbook',  status: 'canonical', tags: ['onboarding','hr'], size: '4.2kB' },
      { type: 'doc', name: 'first-week-agenda.md',          kind: 'runbook',  status: 'canonical', tags: ['onboarding'], size: '2.8kB' },
      { type: 'doc', name: 'equipment-provisioning.md',     kind: 'policy',   status: 'draft',     tags: ['it','hardware'], size: '1.9kB' },
    ]},
    { type: 'folder', name: 'policies', children: [
      { type: 'doc', name: 'remote-work-policy.md',         kind: 'policy',   status: 'canonical', tags: ['policy','remote'], size: '6.4kB' },
      { type: 'doc', name: 'expense-reimbursement.md',      kind: 'policy',   status: 'canonical', tags: ['policy','finance'], size: '3.1kB' },
      { type: 'doc', name: 'data-protection.md',            kind: 'policy',   status: 'review',    tags: ['gdpr','legal'], size: '8.7kB' },
    ]},
    { type: 'folder', name: 'benefits', children: [
      { type: 'doc', name: 'health-insurance-2026.md',      kind: 'reference', status: 'canonical', tags: ['benefits'], size: '5.5kB' },
      { type: 'doc', name: 'pto-guidelines.md',             kind: 'policy',    status: 'canonical', tags: ['pto','benefits'], size: '2.2kB' },
    ]},
    { type: 'folder', name: 'decisions', children: [
      { type: 'doc', name: '2026-q1-compensation-review.md',kind: 'decision',  status: 'canonical', tags: ['decision','comp'], size: '3.8kB' },
    ]},
  ],
  'legal-vault': [
    { type: 'folder', name: 'contracts', children: [
      { type: 'doc', name: 'msa-template-v3.md', kind: 'reference', status: 'canonical', tags: ['contract'], size: '12kB' },
    ]},
  ],
  'finance-ops': [
    { type: 'folder', name: 'processes', children: [
      { type: 'doc', name: 'month-close-checklist.md', kind: 'runbook', status: 'canonical', tags: ['ops'], size: '5.1kB' },
    ]},
  ],
  'engineering': [
    { type: 'folder', name: 'runbooks', children: [
      { type: 'doc', name: 'incident-response.md',  kind: 'runbook', status: 'canonical', tags: ['ops','oncall'], size: '7.2kB' },
      { type: 'doc', name: 'deploy-rollback.md',    kind: 'runbook', status: 'canonical', tags: ['ops','deploy'], size: '4.4kB' },
    ]},
    { type: 'folder', name: 'adr', children: [
      { type: 'doc', name: '0012-vector-store-choice.md', kind: 'decision', status: 'canonical', tags: ['rag','adr'], size: '6.8kB' },
    ]},
  ],
};

const SAMPLE_DOC = {
  id: 'doc_hrp_remote_2026',
  project: 'hr-portal',
  path: 'policies/remote-work-policy.md',
  title: 'Remote Work Policy',
  kind: 'policy',
  status: 'canonical',
  owners: ['elena.ricci', 'marco'],
  reviewers: ['chiara.r'],
  tags: ['policy', 'remote', 'hr', '2026'],
  version: '2026.3.1',
  versionHash: 'a7f2c19e4b8d',
  indexedAt: '2026-04-18 09:42',
  chunksCount: 14,
  bodyMd: `---
title: Remote Work Policy
type: policy
status: canonical
owners: [elena.ricci, marco]
tags: [policy, remote, hr, 2026]
---

# Remote Work Policy

> [!note]
> This policy supersedes the 2024 version. See [[remote-work-policy-2024]] for history.

ACME employees may work remotely up to **3 days per week** with manager approval. Full remote arrangements require VP sign-off and are reviewed annually.

## Eligibility

Eligibility is role-based. See [[role-remote-eligibility]] for the current matrix.

- Engineering: full remote allowed
- Customer Success: hybrid only (2 days on-site)
- Operations: case-by-case with [[equipment-provisioning]]

## Expense reimbursement

Employees working remote more than 50% of the time receive a monthly stipend of **€80** for internet and utilities. See [[expense-reimbursement]] for the claims process.

| Arrangement      | Stipend | Equipment      |
|------------------|---------|----------------|
| Hybrid (≤2 days) | —       | Standard kit   |
| Hybrid (3 days)  | €40     | Standard kit   |
| Full remote      | €80     | Enhanced kit   |

## Security requirements

All remote devices must comply with [[data-protection]]. VPN is mandatory when accessing internal tools.

\`\`\`bash
# Verify VPN status before connecting
acme vpn status --verify
\`\`\`
`,
};

const RELATED_GRAPH = {
  nodes: [
    { id: 'remote-work-policy',      label: 'remote-work-policy',    kind: 'policy',    x: 0,    y: 0,   focus: true },
    { id: 'equipment-provisioning',  label: 'equipment-provisioning',kind: 'policy',    x: -180, y: -90 },
    { id: 'expense-reimbursement',   label: 'expense-reimbursement', kind: 'policy',    x: 180,  y: -60 },
    { id: 'data-protection',         label: 'data-protection',       kind: 'policy',    x: -120, y: 110 },
    { id: 'role-remote-eligibility', label: 'role-remote-eligibility',kind: 'reference',x: 180,  y: 110 },
    { id: 'q1-remote-survey',        label: 'q1-remote-survey',      kind: 'decision',  x: -230, y: 20  },
    { id: 'pto-guidelines',          label: 'pto-guidelines',        kind: 'policy',    x: 230,  y: 40  },
  ],
  edges: [
    ['remote-work-policy','equipment-provisioning'],
    ['remote-work-policy','expense-reimbursement'],
    ['remote-work-policy','data-protection'],
    ['remote-work-policy','role-remote-eligibility'],
    ['equipment-provisioning','q1-remote-survey'],
    ['expense-reimbursement','pto-guidelines'],
  ]
};

const CONVERSATIONS = [
  { id: 'c1', title: 'Remote work stipend for new hires',  project: 'hr-portal',    when: '2m ago',  unread: true  },
  { id: 'c2', title: 'GDPR retention for audit logs',       project: 'legal-vault',  when: '1h ago',  unread: false },
  { id: 'c3', title: 'Month-close sign-off workflow',       project: 'finance-ops',  when: 'today',   unread: false },
  { id: 'c4', title: 'Vector store selection rationale',    project: 'engineering',  when: 'today',   unread: false },
  { id: 'c5', title: 'Onboarding checklist differences EU/US', project: 'hr-portal', when: 'yesterday', unread: false },
  { id: 'c6', title: 'Equipment provisioning for full remote',project: 'hr-portal',  when: '2 days ago', unread: false },
];

const SAMPLE_REPLY =
`Based on the current **[[remote-work-policy]]**, new hires are eligible for the remote work stipend starting from their **second month** of employment, subject to the following conditions:

1. Their role must be classified as **hybrid (3 days)** or **full remote** in the [[role-remote-eligibility]] matrix
2. Direct manager approval is recorded in the HRIS
3. Completion of the [[new-hire-checklist]] mandatory security training

The stipend is **€40/month for hybrid (3-day)** and **€80/month for full remote** arrangements. Claims follow the standard process in [[expense-reimbursement]].

| Arrangement      | Month 1 | Month 2+ |
|------------------|---------|----------|
| Hybrid (≤2 days) | —       | —        |
| Hybrid (3 days)  | —       | €40      |
| Full remote      | —       | €80      |

Contact HR if special circumstances apply (e.g., relocation packages).`;

const ACTIVITY = [
  { id: 1, actor: 'elena.ricci',  action: 'promoted', target: 'remote-work-policy.md',      project: 'hr-portal',   when: '2m ago',  icon: 'Sparkles' },
  { id: 2, actor: 'marco',        action: 'updated',  target: 'data-protection.md',         project: 'hr-portal',   when: '14m ago', icon: 'Edit' },
  { id: 3, actor: 'scheduler',    action: 'ran',      target: 'kb:rebuild-graph',           project: 'all',         when: '28m ago', icon: 'Terminal' },
  { id: 4, actor: 's.colombo',    action: 'added',    target: 'new-hire-checklist.md',      project: 'hr-portal',   when: '1h ago',  icon: 'Plus' },
  { id: 5, actor: 'g.deluca',     action: 'commented',target: 'msa-template-v3.md',         project: 'legal-vault', when: '2h ago',  icon: 'Chat' },
  { id: 6, actor: 'ai-insights',  action: 'suggested',target: '4 promotion candidates',     project: 'engineering', when: '3h ago',  icon: 'Sparkles' },
  { id: 7, actor: 't.greco',      action: 'deleted',  target: 'old-deploy-process.md',      project: 'engineering', when: '5h ago',  icon: 'Trash' },
  { id: 8, actor: 'scheduler',    action: 'pruned',   target: '382 embedding cache rows',   project: 'all',         when: 'today',   icon: 'Clock' },
];

const INSIGHTS = [
  { id: 'i1', kind: 'promote', severity: 'high',   title: '4 documents ready for canonical promotion', detail: 'High retrieval frequency over 30d with no negative feedback.', count: 4, actionable: true },
  { id: 'i2', kind: 'orphan',  severity: 'medium', title: '7 orphan documents detected',                detail: 'No edges, no citations in last 60d.', count: 7, actionable: true },
  { id: 'i3', kind: 'tags',    severity: 'low',    title: '12 documents missing tags',                  detail: 'AI can suggest tags based on content and project conventions.', count: 12, actionable: true },
  { id: 'i4', kind: 'stale',   severity: 'medium', title: '3 stale canonical docs',                     detail: 'Indexed >180d with negative feedback on retrieval.', count: 3, actionable: true },
  { id: 'i5', kind: 'gap',     severity: 'high',   title: 'Coverage gap: "parental leave EU"',         detail: '38 queries in 14d with 0 citations.', count: 38, actionable: false },
];

const LOGS = [
  { t: '14:02:18', level: 'INFO',  project: 'hr-portal',    model: 'claude-sonnet-4.5', lat: 842,  tok: 1248, msg: 'chat: remote work stipend eligibility'  },
  { t: '14:01:55', level: 'INFO',  project: 'engineering',  model: 'claude-haiku-4.5',  lat: 412,  tok: 620,  msg: 'chat: incident response rollback steps' },
  { t: '14:01:03', level: 'WARN',  project: 'legal-vault',  model: 'claude-sonnet-4.5', lat: 2184, tok: 3120, msg: 'retrieval: low confidence (chunks=1)'   },
  { t: '14:00:41', level: 'INFO',  project: 'finance-ops',  model: 'claude-haiku-4.5',  lat: 318,  tok: 412,  msg: 'chat: month-close sign-off'              },
  { t: '14:00:12', level: 'ERROR', project: 'hr-portal',    model: 'claude-sonnet-4.5', lat: 0,    tok: 0,    msg: 'embedding provider timeout (retry 1/3)' },
  { t: '13:59:48', level: 'INFO',  project: 'hr-portal',    model: 'claude-sonnet-4.5', lat: 612,  tok: 892,  msg: 'chat: onboarding equipment'              },
  { t: '13:59:10', level: 'INFO',  project: 'engineering',  model: 'claude-sonnet-4.5', lat: 721,  tok: 1104, msg: 'chat: vector store selection'            },
  { t: '13:58:52', level: 'INFO',  project: 'hr-portal',    model: 'claude-haiku-4.5',  lat: 298,  tok: 380,  msg: 'chat: PTO policy'                         },
];

// rand timeseries
function mkSeries(len, base = 100, spread = 30) {
  const out = [];
  let v = base;
  for (let i = 0; i < len; i++) {
    v += (Math.random() - 0.5) * spread;
    out.push(Math.max(0, Math.round(v)));
  }
  return out;
}

Object.assign(window, {
  PROJECTS, USERS, KB_TREE, SAMPLE_DOC, RELATED_GRAPH, CONVERSATIONS, SAMPLE_REPLY,
  ACTIVITY, INSIGHTS, LOGS, mkSeries,
});
