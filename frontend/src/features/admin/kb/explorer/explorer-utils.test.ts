import { describe, it, expect } from 'vitest';
import type { KbTreeNode } from '../../admin.api';
import {
    breadcrumbSegments,
    childrenAtPath,
    deepFilterDocs,
    descendantDocCount,
    findDocById,
    folderEntries,
    resolveExistingPath,
} from './explorer-utils';

function doc(name: string, path: string, id: number, title: string | null = null): KbTreeNode {
    return {
        type: 'doc',
        name,
        path,
        meta: {
            id,
            project_key: 'hr-portal',
            title,
            slug: null,
            canonical_type: null,
            canonical_status: null,
            is_canonical: false,
            indexed_at: null,
            updated_at: null,
            deleted_at: null,
        },
    };
}

const tree: KbTreeNode[] = [
    {
        type: 'folder',
        name: 'policies',
        path: 'policies',
        children: [
            doc('remote.md', 'policies/remote.md', 1, 'Remote Work Policy'),
            {
                type: 'folder',
                name: 'hr',
                path: 'policies/hr',
                children: [doc('pto.md', 'policies/hr/pto.md', 2, 'PTO')],
            },
        ],
    },
    doc('readme.md', 'readme.md', 3),
];

describe('explorer-utils', () => {
    it('childrenAtPath returns the root level for empty path', () => {
        const level = childrenAtPath(tree, '');
        expect(level).not.toBeNull();
        expect(level).toHaveLength(2);
    });

    it('childrenAtPath descends into a nested folder', () => {
        const level = childrenAtPath(tree, 'policies/hr');
        expect(level).not.toBeNull();
        expect(level).toHaveLength(1);
        expect(level?.[0]?.path).toBe('policies/hr/pto.md');
    });

    it('childrenAtPath returns null for an unknown path', () => {
        expect(childrenAtPath(tree, 'does/not/exist')).toBeNull();
    });

    it('folderEntries separates folders from docs', () => {
        const level = childrenAtPath(tree, 'policies')!;
        const { folders, docs } = folderEntries(level);
        expect(folders.map((f) => f.name)).toEqual(['hr']);
        expect(docs.map((d) => d.name)).toEqual(['remote.md']);
    });

    it('descendantDocCount counts docs across nested folders', () => {
        const policies = tree[0];
        expect(policies.type).toBe('folder');
        // remote.md + policies/hr/pto.md = 2
        expect(descendantDocCount(policies as Extract<KbTreeNode, { type: 'folder' }>)).toBe(2);
    });

    it('deepFilterDocs flattens matches across folder depth (by title)', () => {
        const matches = deepFilterDocs(tree, 'pto');
        expect(matches).toHaveLength(1);
        expect(matches[0]?.path).toBe('policies/hr/pto.md');
    });

    it('deepFilterDocs matches by path segment too', () => {
        const matches = deepFilterDocs(tree, 'policies/');
        expect(matches.map((m) => m.path).sort()).toEqual([
            'policies/hr/pto.md',
            'policies/remote.md',
        ]);
    });

    it('deepFilterDocs returns nothing for an empty term', () => {
        expect(deepFilterDocs(tree, '   ')).toEqual([]);
    });

    it('findDocById locates a nested doc by id', () => {
        const found = findDocById(tree, 2);
        expect(found?.path).toBe('policies/hr/pto.md');
    });

    it('findDocById returns null for an unknown id', () => {
        expect(findDocById(tree, 999999)).toBeNull();
    });

    it('breadcrumbSegments builds an accumulating trail', () => {
        expect(breadcrumbSegments('policies/hr')).toEqual([
            { name: 'policies', path: 'policies' },
            { name: 'hr', path: 'policies/hr' },
        ]);
    });

    it('breadcrumbSegments returns empty array at root', () => {
        expect(breadcrumbSegments('')).toEqual([]);
    });

    it('resolveExistingPath keeps a live path unchanged', () => {
        expect(resolveExistingPath(tree, 'policies/hr')).toBe('policies/hr');
    });

    it('resolveExistingPath walks up to the nearest live ancestor', () => {
        // policies exists, policies/hr/gone does not → falls back to policies/hr
        expect(resolveExistingPath(tree, 'policies/hr/gone')).toBe('policies/hr');
    });

    it('resolveExistingPath falls back to root when no ancestor exists', () => {
        expect(resolveExistingPath(tree, 'totally/unknown')).toBe('');
    });
});
