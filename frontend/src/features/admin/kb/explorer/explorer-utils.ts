import type { KbTreeDocNode, KbTreeFolderNode, KbTreeNode } from '../../admin.api';

/*
 * Pure helpers that turn the flat KB tree (from `useKbTree`, same cache
 * the TreeView already loads) into the filesystem-explorer view: the
 * children at a virtual path, breadcrumb segments, descendant counts,
 * and a flattened search across every folder.
 *
 * All functions are side-effect free so they unit-test in isolation
 * and memoise cheaply inside the component.
 */

export interface FolderEntries {
    folders: KbTreeFolderNode[];
    docs: KbTreeDocNode[];
}

export interface Crumb {
    name: string;
    path: string;
}

/**
 * The list of child nodes living directly under `path` ('' = root).
 * Returns null when the path does not resolve to a folder — the caller
 * decides whether to fall back to the nearest ancestor.
 */
export function childrenAtPath(tree: KbTreeNode[], path: string): KbTreeNode[] | null {
    if (path === '') {
        return tree;
    }

    let level = tree;
    for (const segment of path.split('/')) {
        const folder = level.find(
            (n): n is KbTreeFolderNode => n.type === 'folder' && n.name === segment,
        );
        if (folder === undefined) {
            return null;
        }
        level = folder.children;
    }
    return level;
}

/** Split a level's nodes into folders + docs (folders first by convention). */
export function folderEntries(children: KbTreeNode[]): FolderEntries {
    const folders: KbTreeFolderNode[] = [];
    const docs: KbTreeDocNode[] = [];
    for (const node of children) {
        if (node.type === 'folder') {
            folders.push(node);
        } else {
            docs.push(node);
        }
    }
    return { folders, docs };
}

/** Total docs anywhere beneath a folder (drives the tile count badge). */
export function descendantDocCount(folder: KbTreeFolderNode): number {
    let count = 0;
    for (const child of folder.children) {
        if (child.type === 'doc') {
            count++;
        } else {
            count += descendantDocCount(child);
        }
    }
    return count;
}

/**
 * Every doc whose path / name / title contains `term`, flattened across
 * the whole tree — the explorer's search mirrors the media-gallery
 * behaviour of surfacing matches regardless of folder depth.
 */
export function deepFilterDocs(tree: KbTreeNode[], term: string): KbTreeDocNode[] {
    const needle = term.trim().toLowerCase();
    if (needle === '') {
        return [];
    }

    const out: KbTreeDocNode[] = [];
    const walk = (nodes: KbTreeNode[]) => {
        for (const node of nodes) {
            if (node.type === 'folder') {
                walk(node.children);
                continue;
            }
            const title = (node.meta.title ?? '').toLowerCase();
            if (
                node.path.toLowerCase().includes(needle) ||
                node.name.toLowerCase().includes(needle) ||
                title.includes(needle)
            ) {
                out.push(node);
            }
        }
    };
    walk(tree);
    return out;
}

/** Find a doc node anywhere in the tree by its document id (preview lookup). */
export function findDocById(tree: KbTreeNode[], id: number): KbTreeDocNode | null {
    for (const node of tree) {
        if (node.type === 'doc') {
            if (node.meta.id === id) {
                return node;
            }
            continue;
        }
        const found = findDocById(node.children, id);
        if (found !== null) {
            return found;
        }
    }
    return null;
}

/** Breadcrumb trail for `path` ('' → empty array → root-only crumb). */
export function breadcrumbSegments(path: string): Crumb[] {
    if (path === '') {
        return [];
    }
    const out: Crumb[] = [];
    let acc = '';
    for (const part of path.split('/')) {
        acc = acc === '' ? part : `${acc}/${part}`;
        out.push({ name: part, path: acc });
    }
    return out;
}

/**
 * Resolve `path` to the nearest ancestor that still exists in the tree
 * (R17). After a delete empties a folder, or a deep-link points at a
 * path that never existed, the explorer must not render a dead view —
 * it walks up to the closest live folder, falling back to root.
 */
export function resolveExistingPath(tree: KbTreeNode[], path: string): string {
    if (childrenAtPath(tree, path) !== null) {
        return path;
    }
    const parts = path.split('/');
    while (parts.length > 0) {
        parts.pop();
        const candidate = parts.join('/');
        if (childrenAtPath(tree, candidate) !== null) {
            return candidate;
        }
    }
    return '';
}
