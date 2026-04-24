import { useMemo } from 'react';
import { useKbGraph } from './kb-document.api';
import type { KbGraphEdge, KbGraphNode } from '../admin.api';

/*
 * Phase G4 — GraphTab. Hand-rolled SVG radial layout for the 1-hop
 * subgraph of a canonical doc. Pure SVG, no library — reactflow and
 * sigma both pull >150 KB gzipped for a feature where we show ≤ 50
 * nodes in a fixed circle.
 *
 * Testids (R11):
 *   - kb-graph                  wrapper; data-state=loading|ready|error|empty
 *   - kb-graph-node-<uid>       one per node; data-type, data-role
 *   - kb-graph-edge-<uid>       one per edge; data-edge-type
 *
 * Empty-state: when the document is raw (no canonical seed node yet),
 * the endpoint returns 200 + nodes=[] — we render a neutral message
 * instead of an error, matching the degrade-gracefully contract of
 * the canonical layer (CLAUDE.md §6).
 */

export interface GraphTabProps {
    documentId: number;
}

const CANVAS_SIZE = 440;
const CENTER_RADIUS = 26;
const NEIGHBOR_RADIUS = 20;
const RING_RADIUS = 160;

export function GraphTab({ documentId }: GraphTabProps) {
    const query = useKbGraph(documentId);

    if (query.isLoading) {
        return (
            <div
                data-testid="kb-graph"
                data-state="loading"
                aria-busy="true"
                style={{ color: 'var(--fg-3)', padding: 16 }}
            >
                <div data-testid="kb-graph-loading">Loading graph…</div>
            </div>
        );
    }

    if (query.isError || !query.data) {
        return (
            <div
                data-testid="kb-graph"
                data-state="error"
                aria-busy="false"
                style={{ color: 'var(--danger-fg, #b91c1c)', padding: 16, fontSize: 12.5 }}
            >
                <div data-testid="kb-graph-error">
                    Could not load the graph. The server returned an error — retry or check the
                    document's canonical status.
                </div>
            </div>
        );
    }

    const { nodes, edges, meta } = query.data;

    if (nodes.length === 0) {
        return (
            <div
                data-testid="kb-graph"
                data-state="empty"
                aria-busy="false"
                style={{
                    color: 'var(--fg-3)',
                    padding: 24,
                    fontSize: 12.5,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 8,
                    alignItems: 'center',
                    textAlign: 'center',
                }}
            >
                <div data-testid="kb-graph-empty" style={{ fontSize: 13, color: 'var(--fg-2)' }}>
                    No graph yet — canonicalize the document to populate its neighborhood.
                </div>
                <div style={{ fontSize: 11, maxWidth: 380 }}>
                    Adding a YAML frontmatter fence with an `id:` / `slug:` and any wikilinks or
                    `related:` entries will dispatch the indexer and render the 1-hop graph here.
                </div>
            </div>
        );
    }

    return (
        <div
            data-testid="kb-graph"
            data-state="ready"
            data-center-uid={meta.center_node_uid ?? ''}
            aria-busy="false"
            style={{ display: 'flex', flexDirection: 'column', gap: 12 }}
        >
            <GraphLegend nodes={nodes} edges={edges} />
            <GraphCanvas nodes={nodes} edges={edges} />
        </div>
    );
}

function GraphLegend({ nodes, edges }: { nodes: KbGraphNode[]; edges: KbGraphEdge[] }) {
    return (
        <div
            data-testid="kb-graph-legend"
            style={{
                display: 'flex',
                gap: 12,
                fontSize: 11,
                color: 'var(--fg-3)',
                fontFamily: 'var(--font-mono)',
                padding: '6px 10px',
                border: '1px solid var(--hairline)',
                borderRadius: 8,
                background: 'var(--bg-1)',
            }}
        >
            <span>
                <strong style={{ color: 'var(--fg-1)' }}>{nodes.length}</strong> nodes
            </span>
            <span>
                <strong style={{ color: 'var(--fg-1)' }}>{edges.length}</strong> edges
            </span>
        </div>
    );
}

interface PositionedNode extends KbGraphNode {
    x: number;
    y: number;
}

function GraphCanvas({ nodes, edges }: { nodes: KbGraphNode[]; edges: KbGraphEdge[] }) {
    // Compute radial positions: center node at the middle, neighbours
    // arranged on a circle around it. Deterministic order (stable angle
    // derived from index) so the layout doesn't shift between renders.
    const positioned = useMemo<PositionedNode[]>(() => {
        const cx = CANVAS_SIZE / 2;
        const cy = CANVAS_SIZE / 2;
        const center = nodes.find((n) => n.role === 'center');
        const neighbors = nodes.filter((n) => n.role !== 'center');

        const placed: PositionedNode[] = [];
        if (center) {
            placed.push({ ...center, x: cx, y: cy });
        }

        // Evenly distribute neighbors on the ring. When there is no
        // center (e.g. future multi-seed variant) the ring still
        // renders — we fall back to placing every node on the ring.
        const list = center ? neighbors : nodes;
        const n = list.length;
        for (let i = 0; i < n; i++) {
            const angle = (2 * Math.PI * i) / Math.max(n, 1);
            placed.push({
                ...list[i],
                x: cx + RING_RADIUS * Math.cos(angle),
                y: cy + RING_RADIUS * Math.sin(angle),
            });
        }
        return placed;
    }, [nodes]);

    const indexByUid = useMemo(() => {
        const map = new Map<string, PositionedNode>();
        for (const p of positioned) {
            map.set(p.uid, p);
        }
        return map;
    }, [positioned]);

    return (
        <div
            style={{
                border: '1px solid var(--hairline)',
                borderRadius: 10,
                background: 'var(--bg-0)',
                padding: 12,
                overflow: 'auto',
            }}
        >
            <svg
                role="img"
                aria-label="1-hop canonical subgraph"
                width={CANVAS_SIZE}
                height={CANVAS_SIZE}
                viewBox={`0 0 ${CANVAS_SIZE} ${CANVAS_SIZE}`}
                style={{ maxWidth: '100%', height: 'auto', display: 'block' }}
            >
                {/* Edges first so they render under the nodes. */}
                {edges.map((edge) => {
                    const from = indexByUid.get(edge.from);
                    const to = indexByUid.get(edge.to);
                    if (!from || !to) {
                        return null;
                    }
                    return (
                        <g
                            key={edge.uid}
                            data-testid={`kb-graph-edge-${edge.uid}`}
                            data-edge-type={edge.type}
                        >
                            <line
                                x1={from.x}
                                y1={from.y}
                                x2={to.x}
                                y2={to.y}
                                stroke="var(--hairline-strong, #888)"
                                strokeWidth={1 + Math.min(2, edge.weight)}
                                strokeOpacity={0.65}
                            />
                            <title>
                                {edge.type} ({edge.provenance})
                            </title>
                        </g>
                    );
                })}
                {positioned.map((node) => {
                    const isCenter = node.role === 'center';
                    const r = isCenter ? CENTER_RADIUS : NEIGHBOR_RADIUS;
                    const fill = isCenter
                        ? 'var(--accent, #6366f1)'
                        : node.dangling
                          ? 'var(--bg-1)'
                          : 'var(--grad-accent-soft, rgba(99,102,241,0.2))';
                    const stroke = node.dangling
                        ? 'var(--danger-fg, #b91c1c)'
                        : isCenter
                          ? 'var(--accent, #6366f1)'
                          : 'var(--hairline-strong, #888)';
                    return (
                        <g
                            key={node.uid}
                            data-testid={`kb-graph-node-${node.uid}`}
                            data-type={node.type}
                            data-role={node.role}
                            data-dangling={node.dangling ? 'true' : 'false'}
                        >
                            <circle
                                cx={node.x}
                                cy={node.y}
                                r={r}
                                fill={fill}
                                stroke={stroke}
                                strokeWidth={isCenter ? 2.5 : 1.5}
                                strokeDasharray={node.dangling ? '3 3' : undefined}
                            />
                            <text
                                x={node.x}
                                y={node.y + r + 14}
                                textAnchor="middle"
                                fontSize={10.5}
                                fontFamily="var(--font-mono)"
                                fill="var(--fg-1)"
                            >
                                {truncate(node.label, 22)}
                            </text>
                            <text
                                x={node.x}
                                y={node.y + r + 26}
                                textAnchor="middle"
                                fontSize={9}
                                fontFamily="var(--font-mono)"
                                fill="var(--fg-3)"
                            >
                                {node.type}
                            </text>
                            <title>
                                {node.label} — {node.type}
                                {node.dangling ? ' (dangling)' : ''}
                            </title>
                        </g>
                    );
                })}
            </svg>
        </div>
    );
}

function truncate(s: string, max: number): string {
    return s.length > max ? s.slice(0, max - 1) + '…' : s;
}
