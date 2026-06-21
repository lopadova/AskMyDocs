/*
 * Dev-only seed data ported from `design-reference/project/data/seed.jsx`.
 * PR5-I will wire real API endpoints; today we need realistic shapes so
 * the shell (avatars, command palette) renders the same silhouette as
 * the design mockup.
 *
 * The old `PROJECTS` literal (and its `Project` type) is gone: the
 * topbar switcher now selects TEAMS from the team-store, project lists
 * derive from tenant-scoped endpoints (R18), and the chat takes its
 * active project from the current team's memberships.
 */

export type SeedUser = {
    id: number;
    name: string;
    email: string;
    role: 'super-admin' | 'admin' | 'dpo' | 'editor' | 'viewer';
    projects: string[];
    active: boolean;
    last: string;
    avatar: string;
    color: string;
};

export const USERS: SeedUser[] = [
    {
        id: 1,
        name: 'Elena Ricci',
        email: 'elena.ricci@acme.io',
        role: 'super-admin',
        projects: ['hr-portal', 'legal-vault', 'finance-ops', 'engineering'],
        active: true,
        last: '2m ago',
        avatar: 'ER',
        color: '#8b5cf6',
    },
    {
        id: 2,
        name: 'Marco Bianchi',
        email: 'marco@acme.io',
        role: 'admin',
        projects: ['hr-portal', 'legal-vault'],
        active: true,
        last: '14m ago',
        avatar: 'MB',
        color: '#22d3ee',
    },
    {
        id: 3,
        name: 'Sara Colombo',
        email: 's.colombo@acme.io',
        role: 'editor',
        projects: ['hr-portal'],
        active: true,
        last: '1h ago',
        avatar: 'SC',
        color: '#f97316',
    },
    {
        id: 4,
        name: 'Giovanni De Luca',
        email: 'g.deluca@acme.io',
        role: 'editor',
        projects: ['legal-vault', 'finance-ops'],
        active: true,
        last: '3h ago',
        avatar: 'GD',
        color: '#a3e635',
    },
    {
        id: 5,
        name: 'Anna Moretti',
        email: 'anna.m@acme.io',
        role: 'viewer',
        projects: ['engineering'],
        active: true,
        last: 'today',
        avatar: 'AM',
        color: '#e11d48',
    },
];
