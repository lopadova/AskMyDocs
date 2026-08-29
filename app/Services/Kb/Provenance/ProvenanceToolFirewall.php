<?php

declare(strict_types=1);

namespace App\Services\Kb\Provenance;

use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;

/**
 * Decides whether a turn grounded in externally-authored content may reach
 * the tool loop (ADR 0028 phase 3).
 *
 * The chain this closes is short and entirely ordinary. IMAP ingests content
 * written by anyone who can send an email. That content becomes retrieval
 * grounding. The same platform exposes MCP tools to the model. Nothing
 * between those three facts distinguishes a colleague's runbook from a
 * stranger's instructions, so a paragraph in an inbound email can reach the
 * model as context and ask it to call a tool — and the model has no way to
 * know it should not.
 *
 * The rule is the ADR's, and the asymmetry in it is the point: an
 * `UntrustedExternal` chunk may be **quoted** in an answer, but must never
 * **influence a tool call**. Quoting is what the corpus is for; acting is
 * what an attacker wants. Refusing to quote would break the product to fix
 * the security problem; withholding the tools costs the turn its actions and
 * nothing else.
 *
 * Two things this is NOT:
 *
 * - **Not the Auto-Wiki curation firewall.** That one ranks whether a HUMAN
 *   has vouched for a page. This one records who WROTE it. A human-accepted
 *   page summarising an external email is `accepted` and `UntrustedExternal`
 *   at once, and collapsing the two would let curation launder authorship.
 * - **Not a content filter.** It never inspects what a chunk says. Detecting
 *   an injection by reading it is a losing game; refusing to act on anything
 *   an outsider wrote is not.
 */
final class ProvenanceToolFirewall
{
    /**
     * Assess this turn's grounding.
     *
     * Every block the model can see is considered — primary, graph-expanded
     * and rejected-approach alike. A rejected-approach document is still
     * text in the prompt, and an attacker who can get a paragraph into any
     * of the three has the same leverage.
     */
    public function assess(SearchResult $result): ToolFirewallVerdict
    {
        if (! (bool) config('kb.provenance.tool_firewall.enabled', false)) {
            return ToolFirewallVerdict::disabled();
        }

        $documentIds = $this->documentIds($result);

        if ($documentIds === []) {
            return ToolFirewallVerdict::allowed();
        }

        // Keyed on the primary key alone, and NOT scoped by tenant. That is a
        // deliberate departure from R30, for the same reason the IMAP mailbox
        // lock omits the tenant: the usual argument does not point this way
        // here.
        //
        // R30 exists to stop a query returning rows from another tenant. This
        // query is a DETECTION, so narrowing it cannot leak anything - it can
        // only fail to FIND an untrusted document, and a miss here returns
        // "allowed" and hands over the tools. A tenant filter would add a way
        // to fail OPEN in exchange for no confidentiality it protects.
        //
        // The ids arrive from a retrieval result that AccessScopeScope already
        // scoped, and `id` is a globally unique auto-increment key, so the
        // filter would be redundant on the happy path and harmful on any path
        // where TenantContext has drifted - a queued job, a CLI caller, a
        // future entry point.
        $untrusted = DB::table('knowledge_documents')
            ->whereIn('id', $documentIds)
            ->where('provenance_tier', ProvenanceTier::UntrustedExternal->value)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($untrusted === []) {
            return ToolFirewallVerdict::allowed();
        }

        // Logged because a turn that quietly loses its tools is otherwise
        // indistinguishable from a model that chose not to call one.
        Log::info('kb.provenance.tool_firewall.blocked', [
            'documents' => $untrusted,
        ]);

        return ToolFirewallVerdict::blocked($untrusted);
    }

    /**
     * Whether the firewall is switched on for this deployment.
     *
     * Exposed so the read surfaces can report the policy rather than each
     * one reaching into config and drifting apart (R44).
     */
    public function enabled(): bool
    {
        return (bool) config('kb.provenance.tool_firewall.enabled', false);
    }

    /**
     * Every document id the model can see text from this turn.
     *
     * @return list<int>
     */
    private function documentIds(SearchResult $result): array
    {
        $ids = [];

        foreach ([$result->primary, $result->expanded, $result->rejected] as $block) {
            if (! $block instanceof Collection) {
                continue;
            }

            foreach ($block as $chunk) {
                $id = data_get($chunk, 'knowledge_document_id');

                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }
}
