import { describe, it, expect } from 'vitest';
import { buildGrant, grantHasSuperAdmin, grantToDraft, EMPTY_GRANT_DRAFT, type GrantDraft } from './GrantEditor';

describe('GrantEditor helpers', () => {
    describe('buildGrant', () => {
        it('returns undefined for a wholly-empty draft (so grant is omitted)', () => {
            expect(buildGrant(EMPTY_GRANT_DRAFT)).toBeUndefined();
        });

        it('builds a primary grant from role + comma projects + project_role', () => {
            const draft: GrantDraft = { role: 'member', projects: 'hr-portal, engineering', project_role: 'member', tenants: [] };
            expect(buildGrant(draft)).toEqual({
                role: 'member',
                projects: ['hr-portal', 'engineering'],
                project_role: 'member',
            });
        });

        it('drops empty project tokens and trims', () => {
            const draft: GrantDraft = { role: '', projects: ' a , , b ,', project_role: '', tenants: [] };
            expect(buildGrant(draft)).toEqual({ projects: ['a', 'b'] });
        });

        it('includes per-tenant grants and filters rows missing tenant_id', () => {
            const draft: GrantDraft = {
                role: '',
                projects: '',
                project_role: '',
                tenants: [
                    { tenant_id: 'acme', role: 'editor', projects: 'acme-kb', project_role: 'admin' },
                    { tenant_id: '', role: 'x', projects: '', project_role: '' }, // dropped — no tenant_id
                ],
            };
            expect(buildGrant(draft)).toEqual({
                tenants: [{ tenant_id: 'acme', role: 'editor', projects: ['acme-kb'], project_role: 'admin' }],
            });
        });
    });

    describe('grantHasSuperAdmin', () => {
        it('detects super-admin in the primary role', () => {
            expect(grantHasSuperAdmin({ ...EMPTY_GRANT_DRAFT, role: 'super-admin' })).toBe(true);
        });
        it('detects super-admin in a tenant role', () => {
            expect(
                grantHasSuperAdmin({
                    ...EMPTY_GRANT_DRAFT,
                    tenants: [{ tenant_id: 'acme', role: 'super-admin', projects: '', project_role: '' }],
                }),
            ).toBe(true);
        });
        it('is false for ordinary roles', () => {
            expect(grantHasSuperAdmin({ ...EMPTY_GRANT_DRAFT, role: 'member' })).toBe(false);
        });
    });

    describe('grantToDraft round-trips a server grant', () => {
        it('hydrates primary + tenant grants for the edit flow', () => {
            const draft = grantToDraft({
                role: 'editor',
                projects: ['a', 'b'],
                project_role: 'admin',
                tenants: [{ tenant_id: 'acme', role: 'member', projects: ['x'], project_role: 'member' }],
            });
            expect(draft.role).toBe('editor');
            expect(draft.projects).toBe('a, b');
            expect(draft.project_role).toBe('admin');
            expect(draft.tenants).toEqual([{ tenant_id: 'acme', role: 'member', projects: 'x', project_role: 'member' }]);
            // and it rebuilds back to an equivalent grant
            expect(buildGrant(draft)).toEqual({
                role: 'editor',
                projects: ['a', 'b'],
                project_role: 'admin',
                tenants: [{ tenant_id: 'acme', role: 'member', projects: ['x'], project_role: 'member' }],
            });
        });
        it('null grant → empty draft', () => {
            expect(grantToDraft(null)).toEqual(EMPTY_GRANT_DRAFT);
        });
    });
});
