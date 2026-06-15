/*
 * EvidenceRiskReviewApp — native AskMyDocs admin SPA for the
 * padosoft/laravel-evidence-risk-review core package (v8.13 / P11).
 *
 * Renders four sections over the package HTTP API:
 *   - Reviews   — paginated, tenant-scoped review log + detail drill-down
 *   - Profiles  — the domain risk profiles + the checks each enables
 *   - Taxonomy  — evidence tiers / risk checks / verdicts vocabulary
 *   - Try       — submit an artifact for review (dry-run by default)
 *
 * Conventions: data-testid follows feature-resource-{id}-{action} (R29);
 * every async section exposes data-state=idle|loading|ready|error|empty (R11);
 * API errors render in the DOM, never swallowed (R14); inputs carry real
 * labels and the tab strip is keyboard-reachable (R15). The profile filter +
 * Try-form profile select derive their options from the live /profiles API,
 * never a literal subset (R18).
 */
import { useEffect, useState } from 'react';
import { evidenceApi, evidenceErrorMessage } from './api';
import type {
    Page,
    Paginated,
    ProfileMetadata,
    ReviewLogRow,
    ReviewResult,
    RiskVerdict,
    Taxonomy,
} from './types';

const TABS: Array<{ page: Page; label: string }> = [
    { page: 'reviews', label: 'Reviews' },
    { page: 'profiles', label: 'Profiles' },
    { page: 'taxonomy', label: 'Taxonomy' },
    { page: 'try', label: 'Try a review' },
];

const VERDICT_FILTERS: Array<{ value: RiskVerdict | ''; label: string }> = [
    { value: '', label: 'Any verdict' },
    { value: 'soften', label: 'Soften or worse' },
    { value: 'flag_for_human_review', label: 'Flag or worse' },
    { value: 'remove', label: 'Remove only' },
];

export default function EvidenceRiskReviewApp() {
    const [page, setPage] = useState<Page>('reviews');

    return (
        <div className="err-shell" data-testid="admin-evidence-risk-review-app" data-page={page}>
            <nav className="err-tabs" aria-label="Evidence & Risk Review sections" data-testid="admin-evidence-risk-review-tabs">
                {TABS.map(({ page: item, label }) => (
                    <button
                        key={item}
                        type="button"
                        className={page === item ? 'is-active' : ''}
                        data-testid={`admin-evidence-risk-review-nav-${item}`}
                        aria-current={page === item ? 'page' : undefined}
                        onClick={() => setPage(item)}
                    >
                        {label}
                    </button>
                ))}
            </nav>
            <div className="err-body">
                {page === 'reviews' && <ReviewsTab />}
                {page === 'profiles' && <ProfilesTab />}
                {page === 'taxonomy' && <TaxonomyTab />}
                {page === 'try' && <TryTab />}
            </div>
        </div>
    );
}

function verdictTone(verdict: RiskVerdict): string {
    if (verdict === 'remove') return 'danger';
    if (verdict === 'flag_for_human_review') return 'warn';
    if (verdict === 'soften') return 'info';
    return 'ok';
}

function VerdictBadge({ verdict }: { verdict: RiskVerdict }) {
    return (
        <span className={`err-badge err-badge-${verdictTone(verdict)}`} data-verdict={verdict}>
            {verdict.replace(/_/g, ' ')}
        </span>
    );
}

function useProfiles(): { profiles: ProfileMetadata[]; error: string | null; loaded: boolean } {
    const [profiles, setProfiles] = useState<ProfileMetadata[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loaded, setLoaded] = useState(false);

    useEffect(() => {
        let active = true;
        evidenceApi
            .listProfiles()
            .then((rows) => {
                if (!active) return;
                setProfiles(rows);
                setLoaded(true);
            })
            .catch((cause) => {
                if (!active) return;
                setError(evidenceErrorMessage(cause));
                setLoaded(true);
            });
        return () => {
            active = false;
        };
    }, []);

    return { profiles, error, loaded };
}

function ReviewsTab() {
    const { profiles } = useProfiles();
    const [profile, setProfile] = useState('');
    const [minVerdict, setMinVerdict] = useState<RiskVerdict | ''>('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Paginated<ReviewLogRow> | null>(null);
    const [state, setState] = useState<'loading' | 'ready' | 'error' | 'empty'>('loading');
    const [error, setError] = useState<string | null>(null);
    const [openId, setOpenId] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        setState('loading');
        setError(null);
        evidenceApi
            .listReviews({ page, profile, min_verdict: minVerdict })
            .then((payload) => {
                if (!active) return;
                setData(payload);
                setState(payload.data.length === 0 ? 'empty' : 'ready');
            })
            .catch((cause) => {
                if (!active) return;
                setError(evidenceErrorMessage(cause));
                setState('error');
            });
        return () => {
            active = false;
        };
    }, [page, profile, minVerdict]);

    return (
        <section className="err-section" data-testid="admin-evidence-risk-review-reviews" data-state={state} aria-busy={state === 'loading'}>
            <div className="err-filterbar">
                <label htmlFor="err-reviews-profile">
                    Profile
                    <select
                        id="err-reviews-profile"
                        value={profile}
                        onChange={(event) => {
                            setProfile(event.target.value);
                            setPage(1);
                        }}
                        data-testid="admin-evidence-risk-review-reviews-profile"
                    >
                        <option value="">All profiles</option>
                        {profiles.map((item) => (
                            <option key={item.key} value={item.key}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label htmlFor="err-reviews-verdict">
                    Minimum verdict
                    <select
                        id="err-reviews-verdict"
                        value={minVerdict}
                        onChange={(event) => {
                            setMinVerdict(event.target.value as RiskVerdict | '');
                            setPage(1);
                        }}
                        data-testid="admin-evidence-risk-review-reviews-verdict"
                    >
                        {VERDICT_FILTERS.map((item) => (
                            <option key={item.value || 'any'} value={item.value}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            {state === 'loading' && <p className="err-muted" data-testid="admin-evidence-risk-review-reviews-loading">Loading reviews…</p>}
            {state === 'error' && (
                <div className="err-alert" role="alert" data-testid="admin-evidence-risk-review-reviews-error">
                    {error}
                </div>
            )}
            {state === 'empty' && (
                <p className="err-muted" data-testid="admin-evidence-risk-review-reviews-empty">
                    No reviews recorded for this tenant yet.
                </p>
            )}

            {state === 'ready' && data && (
                <>
                    <table className="err-table" data-testid="admin-evidence-risk-review-reviews-table">
                        <thead>
                            <tr>
                                <th scope="col">Review</th>
                                <th scope="col">Artifact</th>
                                <th scope="col">Profile</th>
                                <th scope="col">Max verdict</th>
                                <th scope="col">Risk</th>
                                <th scope="col">Recorded</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.data.map((row) => (
                                <tr key={row.review_id}>
                                    <td>
                                        <button
                                            type="button"
                                            className="err-link"
                                            data-testid={`admin-evidence-risk-review-row-${row.review_id}-open`}
                                            onClick={() => setOpenId(row.review_id)}
                                        >
                                            {row.review_id.slice(0, 12)}…
                                        </button>
                                    </td>
                                    <td>{row.artifact_id}</td>
                                    <td>{row.profile_key}</td>
                                    <td><VerdictBadge verdict={row.max_verdict} /></td>
                                    <td>{row.risk_score.toFixed(2)}</td>
                                    <td>{row.created_at ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="err-pager" data-testid="admin-evidence-risk-review-reviews-pager">
                        <button
                            type="button"
                            disabled={data.current_page <= 1}
                            onClick={() => setPage((current) => Math.max(1, current - 1))}
                            data-testid="admin-evidence-risk-review-reviews-prev"
                        >
                            Previous
                        </button>
                        <span>
                            Page {data.current_page} of {data.last_page} · {data.total} total
                        </span>
                        <button
                            type="button"
                            disabled={data.current_page >= data.last_page}
                            onClick={() => setPage((current) => current + 1)}
                            data-testid="admin-evidence-risk-review-reviews-next"
                        >
                            Next
                        </button>
                    </div>
                </>
            )}

            {openId !== null && <ReviewDetail reviewId={openId} onClose={() => setOpenId(null)} />}
        </section>
    );
}

function ReviewDetail({ reviewId, onClose }: { reviewId: string; onClose: () => void }) {
    const [result, setResult] = useState<ReviewResult | null>(null);
    const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        setState('loading');
        evidenceApi
            .getReview(reviewId)
            .then((payload) => {
                if (!active) return;
                setResult(payload);
                setState('ready');
            })
            .catch((cause) => {
                if (!active) return;
                setError(evidenceErrorMessage(cause));
                setState('error');
            });
        return () => {
            active = false;
        };
    }, [reviewId]);

    return (
        <aside
            className="err-detail"
            role="dialog"
            aria-label={`Review ${reviewId}`}
            data-testid="admin-evidence-risk-review-detail"
            data-state={state}
        >
            <header className="err-detail-head">
                <h3>Review {reviewId.slice(0, 12)}…</h3>
                <button type="button" aria-label="Close review detail" onClick={onClose} data-testid="admin-evidence-risk-review-detail-close">
                    ✕
                </button>
            </header>
            {state === 'loading' && <p className="err-muted">Loading review…</p>}
            {state === 'error' && (
                <div className="err-alert" role="alert" data-testid="admin-evidence-risk-review-detail-error">
                    {error}
                </div>
            )}
            {state === 'ready' && result && (
                <div data-testid="admin-evidence-risk-review-detail-body">
                    <dl className="err-meta">
                        <div><dt>Profile</dt><dd>{result.profile_key}</dd></div>
                        <div><dt>Risk score</dt><dd>{result.risk_score.toFixed(2)}</dd></div>
                        <div><dt>Reviewed</dt><dd>{result.reviewed_at}</dd></div>
                        <div><dt>LLM calls</dt><dd>{result.budget.llm_calls}</dd></div>
                    </dl>
                    <h4>Findings ({result.findings.length})</h4>
                    {result.findings.length === 0 ? (
                        <p className="err-muted" data-testid="admin-evidence-risk-review-detail-no-findings">No risk findings.</p>
                    ) : (
                        <ul className="err-findings">
                            {result.findings.map((finding, index) => (
                                <li key={`${finding.check_kind}-${finding.claim_id ?? 'none'}-${index}`}>
                                    <VerdictBadge verdict={finding.verdict} />
                                    <span className="err-finding-kind">{finding.check_kind}</span>
                                    <p>{finding.reason}</p>
                                    {finding.suggested_rewrite && <p className="err-muted">↳ {finding.suggested_rewrite}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </aside>
    );
}

function ProfilesTab() {
    const { profiles, error, loaded } = useProfiles();
    const state: 'loading' | 'error' | 'empty' | 'ready' = error
        ? 'error'
        : !loaded
          ? 'loading'
          : profiles.length === 0
            ? 'empty'
            : 'ready';

    return (
        <section className="err-section" data-testid="admin-evidence-risk-review-profiles" data-state={state} aria-busy={state === 'loading'}>
            {error && (
                <div className="err-alert" role="alert" data-testid="admin-evidence-risk-review-profiles-error">
                    {error}
                </div>
            )}
            {state === 'loading' && <p className="err-muted">Loading profiles…</p>}
            {state === 'empty' && (
                <p className="err-muted" data-testid="admin-evidence-risk-review-profiles-empty">
                    No risk profiles are configured.
                </p>
            )}
            <div className="err-grid">
                {profiles.map((profile) => (
                    <article className="err-card" key={profile.key} data-testid={`admin-evidence-risk-review-profile-${profile.key}`}>
                        <h3>{profile.label}</h3>
                        <code>{profile.key}</code>
                        <p>{profile.description}</p>
                        <ul>
                            {profile.enabled_checks.map((check) => (
                                <li key={check}>{check}</li>
                            ))}
                        </ul>
                    </article>
                ))}
            </div>
        </section>
    );
}

function TaxonomyTab() {
    const [taxonomy, setTaxonomy] = useState<Taxonomy | null>(null);
    const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        evidenceApi
            .taxonomy()
            .then((payload) => {
                if (!active) return;
                setTaxonomy(payload);
                setState('ready');
            })
            .catch((cause) => {
                if (!active) return;
                setError(evidenceErrorMessage(cause));
                setState('error');
            });
        return () => {
            active = false;
        };
    }, []);

    return (
        <section className="err-section" data-testid="admin-evidence-risk-review-taxonomy" data-state={state} aria-busy={state === 'loading'}>
            {state === 'loading' && <p className="err-muted">Loading taxonomy…</p>}
            {state === 'error' && (
                <div className="err-alert" role="alert" data-testid="admin-evidence-risk-review-taxonomy-error">
                    {error}
                </div>
            )}
            {state === 'ready' && taxonomy && (
                <>
                    <h3>Evidence tiers</h3>
                    <table className="err-table" data-testid="admin-evidence-risk-review-taxonomy-tiers">
                        <thead>
                            <tr>
                                <th scope="col">Rank</th>
                                <th scope="col">Key</th>
                                <th scope="col">Label</th>
                                <th scope="col">Built-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...taxonomy.tiers].sort((a, b) => a.rank - b.rank).map((tier) => (
                                <tr key={tier.key}>
                                    <td>{tier.rank}</td>
                                    <td><code>{tier.key}</code></td>
                                    <td>{tier.label}</td>
                                    <td>{tier.builtin ? 'yes' : 'no'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <h3>Risk checks</h3>
                    <ul className="err-chips">
                        {taxonomy.risk_checks.map((check) => (
                            <li key={check}>{check}</li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

function TryTab() {
    const { profiles } = useProfiles();
    const [answer, setAnswer] = useState('This treatment always cures the condition.');
    const [claim, setClaim] = useState('This treatment always cures the condition.');
    const [profile, setProfile] = useState('');
    const [labelViaLlm, setLabelViaLlm] = useState(false);
    const [result, setResult] = useState<ReviewResult | null>(null);
    const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle');
    const [error, setError] = useState<string | null>(null);

    const canSubmit = answer.trim().length > 0 && claim.trim().length > 0;

    async function submit() {
        setState('loading');
        setError(null);
        try {
            const payload: Record<string, unknown> = {
                artifact_id: 'admin-try',
                answer_text: answer,
                claims: [{ id: 'c1', text: claim, assertiveness: 'definitive', source_ids: ['s1'] }],
                sources: [{ id: 's1', title: 'Operator-supplied source' }],
                options: {
                    ...(profile ? { profile_key: profile } : {}),
                    label_via_llm: labelViaLlm,
                    dry_run: true,
                },
            };
            const review = await evidenceApi.submitReview(payload, true);
            setResult(review);
            setState('ready');
        } catch (cause) {
            setError(evidenceErrorMessage(cause));
            setState('error');
        }
    }

    return (
        <section className="err-section" data-testid="admin-evidence-risk-review-try" data-state={state} aria-busy={state === 'loading'}>
            <p className="err-muted">Submit an answer + claim to preview the risk verdict. Runs as a dry-run (nothing is persisted).</p>
            <label htmlFor="err-try-answer">
                Answer text
                <textarea
                    id="err-try-answer"
                    value={answer}
                    onChange={(event) => setAnswer(event.target.value)}
                    data-testid="admin-evidence-risk-review-try-answer"
                />
            </label>
            <label htmlFor="err-try-claim">
                Claim
                <textarea
                    id="err-try-claim"
                    value={claim}
                    onChange={(event) => setClaim(event.target.value)}
                    data-testid="admin-evidence-risk-review-try-claim"
                />
            </label>
            <div className="err-filterbar">
                <label htmlFor="err-try-profile">
                    Profile
                    <select
                        id="err-try-profile"
                        value={profile}
                        onChange={(event) => setProfile(event.target.value)}
                        data-testid="admin-evidence-risk-review-try-profile"
                    >
                        <option value="">Default</option>
                        {profiles.map((item) => (
                            <option key={item.key} value={item.key}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="err-checkbox">
                    <input
                        type="checkbox"
                        checked={labelViaLlm}
                        onChange={(event) => setLabelViaLlm(event.target.checked)}
                        data-testid="admin-evidence-risk-review-try-llm"
                    />
                    Label tiers via LLM
                </label>
            </div>
            <button
                type="button"
                disabled={!canSubmit || state === 'loading'}
                onClick={submit}
                data-testid="admin-evidence-risk-review-try-submit"
            >
                {state === 'loading' ? 'Reviewing…' : 'Run review'}
            </button>

            {state === 'error' && (
                <div className="err-alert" role="alert" data-testid="admin-evidence-risk-review-try-error">
                    {error}
                </div>
            )}
            {state === 'ready' && result && (
                <div className="err-result" data-testid="admin-evidence-risk-review-try-result">
                    <p>
                        Risk score <strong>{result.risk_score.toFixed(2)}</strong> · {result.findings.length} finding(s)
                    </p>
                    <ul className="err-findings">
                        {result.findings.map((finding, index) => (
                            <li key={`${finding.check_kind}-${index}`}>
                                <VerdictBadge verdict={finding.verdict} /> {finding.reason}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
