/*
 * Type definitions for the native AskMyDocs Evidence & Risk Review admin
 * surface (v8.13 / P11).
 *
 * AskMyDocs renders this admin NATIVELY against the core package's HTTP API
 * (padosoft/laravel-evidence-risk-review) rather than loading the separate
 * `-admin` React bundle — the proven convention for every sister admin
 * (PII Redactor, Flow, Eval Harness, AI Act all cross-mount the host's own
 * tree). The shapes below mirror the core package's JSON contract exactly so
 * the responses — served by the package controllers at
 * /api/admin/evidence-risk-review/* — round-trip unchanged.
 */

export type RiskVerdict = 'keep' | 'soften' | 'flag_for_human_review' | 'remove';
export type ClaimAssertiveness = 'definitive' | 'likely' | 'tentative';
export type CostClass = 'cheap' | 'heavy' | 'skipped_over_budget';

export interface EvidenceTier {
    key: string;
    rank: number;
    label: string;
    builtin: boolean;
}

export interface ReviewLogRow {
    review_id: string;
    artifact_id: string;
    profile_key: string;
    max_verdict: RiskVerdict;
    risk_score: number;
    tenant_id: string | null;
    created_at: string | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ReviewFinding {
    check_kind: string;
    claim_id: string | null;
    verdict: RiskVerdict;
    reason: string;
    suggested_rewrite?: string | null;
    confidence: number;
    cost_class: CostClass;
    evidence?: string[];
}

export interface BudgetConsumption {
    llm_calls: number;
    tokens: number;
    heavy_checks: number;
    wall_seconds: number;
}

export interface ReviewResult {
    review_id: string;
    artifact_id: string;
    profile_key: string;
    risk_score: number;
    claim_verdicts: Record<string, RiskVerdict>;
    source_tiers: Record<string, EvidenceTier | string>;
    findings: ReviewFinding[];
    budget: BudgetConsumption;
    reviewed_at: string;
    metadata: Record<string, unknown>;
}

export interface ProfileMetadata {
    key: string;
    label: string;
    description: string;
    enabled_checks: string[];
}

export interface Taxonomy {
    tiers: EvidenceTier[];
    risk_checks: string[];
    risk_verdicts: Array<{ key: string; label?: string }>;
    claim_assertiveness: string[];
}

export interface ReviewLogFilters {
    page?: number;
    profile?: string;
    min_verdict?: RiskVerdict | '';
}

/** The four sections the native admin exposes. */
export type Page = 'reviews' | 'profiles' | 'taxonomy' | 'try';
