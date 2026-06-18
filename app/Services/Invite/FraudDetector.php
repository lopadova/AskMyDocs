<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\AbuseSignal;
use App\Models\InviteCampaign;
use App\Models\Redemption;
use App\Services\Invite\Support\AbuseDecision;
use App\Services\Invite\Support\AssessmentContext;
use App\Services\Invite\Support\PiiHasher;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pure-ish weighted rules engine (docs/10-anti-abuse.md). It assesses a
 * request-boundary context, persists the signals it raises (hashed PII only),
 * and returns the most-severe action across subjects.
 *
 * Two iron rules:
 *   - FAIL-OPEN: any store/rule error degrades to a safe `none` decision and
 *     NEVER becomes a block. Seat safety is the atomic claim's job, not this
 *     gate's — a fragile detector must not DoS legitimate signups.
 *   - GENERIC: the caller only ever learns "rate_limited" — the tripped
 *     signal_type is never surfaced, so the gate is not a probing oracle.
 */
final class FraudDetector
{
    public function __construct(private readonly PiiHasher $hasher)
    {
    }

    public function assess(AssessmentContext $ctx): AbuseDecision
    {
        if (! (bool) config('invite.anti_abuse.enabled', true)) {
            return AbuseDecision::none();
        }

        try {
            return $this->run($ctx);
        } catch (Throwable) {
            // Fail-open — never let a detector fault block a real user.
            return AbuseDecision::none();
        }
    }

    private function run(AssessmentContext $ctx): AbuseDecision
    {
        if ($this->allowlisted($ctx)) {
            return AbuseDecision::none();
        }

        $signals = $this->gather($ctx);

        if ($signals === []) {
            return AbuseDecision::none();
        }

        // Per the threshold table: "≥ block-threshold, OR any severity=block
        // rule → block". A severity=block signal (blacklist, honeypot,
        // self_referral, fingerprint_collision, disposable on a reward
        // campaign) forces block regardless of the running total.
        $hasBlock = false;
        $totals = [];
        foreach ($signals as $signal) {
            $hasBlock = $hasBlock || $signal['severity'] === AbuseSignal::SEVERITY_BLOCK;
            $key = $signal['subject_type'] . ':' . $signal['subject_value'];
            $totals[$key] = ($totals[$key] ?? 0) + (int) $signal['score'];
        }

        // Score per subject; the decision is the MOST SEVERE action across all
        // subjects (block > throttle > flag > none).
        $action = AbuseSignal::ACTION_NONE;
        $maxTotal = 0;
        foreach ($totals as $total) {
            $maxTotal = max($maxTotal, $total);
            $action = $this->moreSevere($action, $this->actionForScore($total));
        }

        if ($hasBlock) {
            $action = AbuseSignal::ACTION_BLOCK;
        }

        $this->persist($signals, $action, $ctx);

        $retryAfter = $action === AbuseSignal::ACTION_THROTTLE
            ? (int) config('invite.anti_abuse.retry_after', 900)
            : null;

        return new AbuseDecision($action, $maxTotal, $retryAfter, $signals);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function gather(AssessmentContext $ctx): array
    {
        $signals = [];

        if ($ctx->honeypot) {
            $signals[] = $this->signal(AbuseSignal::SUBJECT_IP, $this->ipSubject($ctx) ?? 'unknown',
                AbuseSignal::TYPE_HONEYPOT, AbuseSignal::SEVERITY_BLOCK, 100, ['rule' => 'honeypot_filled']);
        }

        $this->blacklistSignals($ctx, $signals);
        $this->disposableEmailSignal($ctx, $signals);
        $this->velocitySignals($ctx, $signals);

        return $signals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     */
    private function blacklistSignals(AssessmentContext $ctx, array &$signals): void
    {
        $list = (array) config('invite.anti_abuse.blocklist', []);

        if ($ctx->ip !== null && in_array($this->hasher->hash($ctx->ip), (array) ($list['ip_hashes'] ?? []), true)) {
            $signals[] = $this->signal(AbuseSignal::SUBJECT_IP, $this->hasher->hash($ctx->ip),
                AbuseSignal::TYPE_BLACKLIST, AbuseSignal::SEVERITY_BLOCK, 100, ['rule' => 'ip_blocklist']);
        }

        if ($ctx->email !== null) {
            $canonical = $this->hasher->canonicalizeEmail($ctx->email);
            $domain = $this->hasher->emailDomain($canonical);
            $emails = array_map(fn ($e) => $this->hasher->canonicalizeEmail((string) $e), (array) ($list['emails'] ?? []));
            if (in_array($canonical, $emails, true) || in_array($domain, (array) ($list['domains'] ?? []), true)) {
                $signals[] = $this->signal(AbuseSignal::SUBJECT_EMAIL, $this->hasher->hash($canonical),
                    AbuseSignal::TYPE_BLACKLIST, AbuseSignal::SEVERITY_BLOCK, 100, ['rule' => 'email_blocklist', 'domain' => $domain]);
            }
        }

        if ($ctx->accountId !== null && in_array($ctx->accountId, array_map('intval', (array) ($list['accounts'] ?? [])), true)) {
            $signals[] = $this->signal(AbuseSignal::SUBJECT_ACCOUNT, (string) $ctx->accountId,
                AbuseSignal::TYPE_BLACKLIST, AbuseSignal::SEVERITY_BLOCK, 100, ['rule' => 'account_blocklist']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     */
    private function disposableEmailSignal(AssessmentContext $ctx, array &$signals): void
    {
        if ($ctx->email === null) {
            return;
        }

        $domain = $this->hasher->emailDomain($this->hasher->canonicalizeEmail($ctx->email));
        if ($domain === '' || ! in_array($domain, (array) config('invite.anti_abuse.disposable_domains', []), true)) {
            return;
        }

        // Domain-only in context — never the local part / full address.
        $severity = $this->isRewardCampaign($ctx->campaign) ? AbuseSignal::SEVERITY_BLOCK : AbuseSignal::SEVERITY_WARN;
        $signals[] = $this->signal(AbuseSignal::SUBJECT_EMAIL, $this->hasher->hash($this->hasher->canonicalizeEmail($ctx->email)),
            AbuseSignal::TYPE_DISPOSABLE_EMAIL, $severity, (int) config('invite.anti_abuse.disposable_score', 40), ['domain' => $domain]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     */
    private function velocitySignals(AssessmentContext $ctx, array &$signals): void
    {
        $now = $ctx->now !== null ? Carbon::instance($ctx->now) : Carbon::now();
        $cfg = (array) config('invite.anti_abuse.velocity', []);

        if ($ctx->accountId !== null) {
            $this->velocityFor($ctx, $signals, AbuseSignal::SUBJECT_ACCOUNT, (string) $ctx->accountId,
                'redeemer_id', $ctx->accountId, $cfg['account'] ?? [], $now, AbuseSignal::TYPE_VELOCITY);
        }

        if ($ctx->ip !== null) {
            $this->velocityFor($ctx, $signals, AbuseSignal::SUBJECT_IP, $this->hasher->hash($ctx->ip),
                'ip', $this->hasher->hash($ctx->ip), $cfg['ip'] ?? [], $now, AbuseSignal::TYPE_RATE_LIMIT);
        }

        if ($ctx->fingerprint !== null) {
            $this->velocityFor($ctx, $signals, AbuseSignal::SUBJECT_FINGERPRINT, $this->hasher->hash($ctx->fingerprint),
                'fingerprint', $this->hasher->hash($ctx->fingerprint), $cfg['fingerprint'] ?? [], $now, AbuseSignal::TYPE_VELOCITY);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $rule
     */
    private function velocityFor(
        AssessmentContext $ctx,
        array &$signals,
        string $subjectType,
        string $subjectValue,
        string $column,
        int|string $columnValue,
        array $rule,
        Carbon $now,
        string $signalType,
    ): void {
        $max = (int) ($rule['max'] ?? 0);
        if ($max <= 0) {
            return;
        }

        $count = Redemption::query()
            ->forTenant($ctx->tenantId)
            ->where($column, $columnValue)
            ->where('redeemed_at', '>=', $now->copy()->subSeconds((int) ($rule['window'] ?? 3600)))
            ->count();

        if ($count >= $max) {
            $signals[] = $this->signal($subjectType, $subjectValue, $signalType,
                AbuseSignal::SEVERITY_WARN, (int) ($rule['score'] ?? 30),
                ['rule' => 'velocity', 'count' => $count, 'max' => $max]);
        }
    }

    private function allowlisted(AssessmentContext $ctx): bool
    {
        $allow = (array) config('invite.anti_abuse.allowlist', []);

        if ($ctx->ip !== null && in_array($ctx->ip, (array) ($allow['ips'] ?? []), true)) {
            return true;
        }

        if ($ctx->email !== null) {
            $domain = $this->hasher->emailDomain($this->hasher->canonicalizeEmail($ctx->email));
            if ($domain !== '' && in_array($domain, (array) ($allow['domains'] ?? []), true)) {
                return true;
            }
        }

        if ($ctx->accountId !== null && in_array($ctx->accountId, array_map('intval', (array) ($allow['accounts'] ?? [])), true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function signal(string $subjectType, string $subjectValue, string $signalType, string $severity, int $score, array $context): array
    {
        return [
            'subject_type' => $subjectType,
            'subject_value' => $subjectValue,
            'signal_type' => $signalType,
            'severity' => $severity,
            'score' => $score,
            'context' => $context,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     */
    private function persist(array $signals, string $action, AssessmentContext $ctx): void
    {
        foreach ($signals as $signal) {
            AbuseSignal::create([
                'tenant_id' => $ctx->tenantId,
                'subject_type' => $signal['subject_type'],
                'subject_value' => $signal['subject_value'],
                'signal_type' => $signal['signal_type'],
                'severity' => $signal['severity'],
                'score' => $signal['score'],
                'action_taken' => $action,
                'context' => $signal['context'],
                'created_at' => Carbon::now(),
            ]);
        }
    }

    private function actionForScore(int $score): string
    {
        $t = (array) config('invite.anti_abuse.thresholds', []);

        return match (true) {
            $score >= (int) ($t['block'] ?? 80) => AbuseSignal::ACTION_BLOCK,
            $score >= (int) ($t['throttle'] ?? 50) => AbuseSignal::ACTION_THROTTLE,
            $score >= (int) ($t['flag'] ?? 25) => AbuseSignal::ACTION_FLAG,
            default => AbuseSignal::ACTION_NONE,
        };
    }

    private function moreSevere(string $a, string $b): string
    {
        $rank = [
            AbuseSignal::ACTION_NONE => 0,
            AbuseSignal::ACTION_FLAG => 1,
            AbuseSignal::ACTION_THROTTLE => 2,
            AbuseSignal::ACTION_BLOCK => 3,
        ];

        return $rank[$a] >= $rank[$b] ? $a : $b;
    }

    private function isRewardCampaign(?InviteCampaign $campaign): bool
    {
        if ($campaign === null) {
            return false;
        }

        return $campaign->type === InviteCampaign::TYPE_REFERRAL
            || $campaign->reward_policy !== null;
    }

    private function ipSubject(AssessmentContext $ctx): ?string
    {
        return $ctx->ip !== null ? $this->hasher->hash($ctx->ip) : null;
    }
}
