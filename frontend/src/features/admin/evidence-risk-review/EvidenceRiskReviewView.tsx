/*
 * EvidenceRiskReviewView — host mount for the native Evidence & Risk Review
 * admin surface (v8.13 / P11).
 *
 * AskMyDocs renders this admin NATIVELY against the core package HTTP API
 * (padosoft/laravel-evidence-risk-review), the same convention used by every
 * other sister admin (PII Redactor / Flow / Eval Harness / AI Act). The
 * separate `-admin` React bundle is composer-required but dont-discovered.
 *
 * R43 — the admin surface is OPT-IN via EVIDENCE_RISK_REVIEW_ADMIN_ENABLED
 * (default-OFF). When the flag is off the package never registers its routes,
 * so the data probe below 404s and the view shows a single clean "unavailable"
 * landing — never a 500, never a storm of error panels. When the flag is on the
 * probe succeeds and the full dashboards mount. Same probe-then-mount strategy
 * as EvalHarnessView, so the feature is safe whether its flag is ON or OFF.
 */
import { useEffect, useState } from 'react';
import { api } from '../../../lib/api';
import EvidenceRiskReviewApp from './cross-mount/App';
import { EVIDENCE_RISK_REVIEW_API_BASE } from './cross-mount/api';
import './cross-mount/cross-mount.css';

type LoadState = 'loading' | 'ready' | 'unavailable';

export function EvidenceRiskReviewView() {
    const [state, setState] = useState<LoadState>('loading');

    useEffect(() => {
        let active = true;
        setState('loading');

        // Probe a real, gated data endpoint. With the admin flag OFF the route
        // is unregistered → 404 → clean "unavailable" landing. With it ON the
        // probe succeeds → mount the dashboards (R43: degrade loudly but clean).
        api.get(`${EVIDENCE_RISK_REVIEW_API_BASE}/reviews`, { params: { page: 1 } })
            .then(() => active && setState('ready'))
            .catch(() => active && setState('unavailable'));

        return () => {
            active = false;
        };
    }, []);

    return (
        <div
            data-testid="admin-evidence-risk-review-host"
            data-state={state}
            data-mount="cross-mount"
            style={{
                flex: 1,
                display: 'flex',
                flexDirection: 'column',
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
                position: 'relative',
                minHeight: 0,
            }}
        >
            {state === 'loading' && (
                <div
                    data-testid="admin-evidence-risk-review-loading"
                    style={{
                        flex: 1,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontFamily: 'var(--font-sans)',
                        fontSize: 13,
                        color: 'var(--fg-2)',
                    }}
                >
                    <span className="shimmer" style={{ padding: '6px 18px', borderRadius: 8 }}>
                        Loading Evidence &amp; Risk Review…
                    </span>
                </div>
            )}

            {state === 'unavailable' && (
                <div
                    data-testid="admin-evidence-risk-review-unavailable"
                    role="status"
                    style={{
                        flex: 1,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: 40,
                        fontFamily: 'var(--font-sans)',
                    }}
                >
                    <div className="panel" style={{ maxWidth: 540, padding: '24px 24px 22px', textAlign: 'center' }}>
                        <h2 style={{ fontSize: 17, fontWeight: 600, margin: '0 0 8px' }}>
                            Evidence &amp; Risk Review is not available
                        </h2>
                        <p style={{ fontSize: 13, color: 'var(--fg-2)', margin: 0, lineHeight: 1.55 }}>
                            The{' '}
                            <code style={{ fontFamily: 'var(--font-mono)' }}>
                                padosoft/laravel-evidence-risk-review
                            </code>{' '}
                            admin API isn&rsquo;t reachable in this environment. Set{' '}
                            <code>EVIDENCE_RISK_REVIEW_ADMIN_ENABLED=true</code> (then{' '}
                            <code>php artisan config:clear</code>) to enable the review-log dashboards.
                        </p>
                    </div>
                </div>
            )}

            {state === 'ready' && <EvidenceRiskReviewApp />}
        </div>
    );
}
