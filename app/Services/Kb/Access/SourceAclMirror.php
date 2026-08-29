<?php

declare(strict_types=1);

namespace App\Services\Kb\Access;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Models\UnmappedSourcePrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;

/**
 * Reflects a source's permission list onto a document, and takes it away
 * again when the source does (ADR 0028 phase 2).
 *
 * Before this, a file shared with three people in Drive became visible to
 * everyone in the project the moment it was ingested. The upstream ACL was
 * read by nobody and stored nowhere; project membership was the only gate,
 * and it is far coarser than what the source actually said.
 *
 * Two properties matter more than anything else here, and both are about
 * what happens when the source says LESS than it did last time:
 *
 * **Mirroring and reconciliation are one operation.** A mirror that only
 * adds rows is a slow leak: revoke a share upstream and it stays granted
 * here forever, which is worse than never having mirrored it, because the
 * permissions now look deliberate. So every pass replaces the mirrored set
 * wholesale - rows the source no longer names are deleted in the same
 * transaction that writes the ones it does.
 *
 * **Not knowing is never treated as knowing.** An incomplete list, an
 * inherited one, a decode failure - none of them are permission lists, and
 * acting on any of them would either revoke access on the strength of a
 * rate-limit or grant it on the strength of a bug. Those passes leave the
 * previous mirror untouched, which keeps the last thing the source actually
 * said in force. That is the conservative direction: a stale restriction
 * denies too little access to too few people, where the alternative
 * republishes a document that was shared with three.
 *
 * Manual rows are never touched. An operator's grant is not the source's to
 * withdraw, and deleting one because upstream stopped mentioning it would be
 * the same bug pointed the other way.
 */
final class SourceAclMirror
{
    public function __construct(
        private readonly PrincipalResolver $resolver,
    ) {}

    /**
     * Reconcile one document against what its source reported.
     */
    public function syncFor(KnowledgeDocument $document, SourceAccess $access): MirrorOutcome
    {
        $skip = $this->reasonToSkip($access);

        if ($skip !== null) {
            // Deliberately leaves the existing mirror in place. See the class
            // docblock: the previous list is the last thing the source
            // actually said, and it is a better answer than either extreme.
            Log::info('kb.source_acl.skipped', [
                'document_id' => $document->getKey(),
                'reason' => $skip,
            ]);

            return MirrorOutcome::skipped($skip);
        }

        $tenantId = (string) $document->tenant_id;

        if ($access->isPublic()) {
            // The source says anyone may read it, so there is nothing to
            // restrict TO. Dropping the mirror returns the document to
            // ordinary project visibility rather than leaving it pinned to
            // whoever happened to be named on the previous sync.
            //
            // All three writes happen in the SAME transaction as the
            // reconciliation branch below (R21): a partial apply here would
            // leave `source_acl_enforced_at` set with zero mirrored rows,
            // which hides the document from the whole project until the next
            // successful sync.
            return DB::transaction(function () use ($document, $tenantId): MirrorOutcome {
                $revoked = $this->clearMirroredRows($document, $tenantId);
                $this->clearQueue($document, $tenantId);
                $this->markRestricted($document, $tenantId, false);

                return MirrorOutcome::applied(0, 0, $revoked, 0);
            });
        }

        return DB::transaction(function () use ($document, $access, $tenantId): MirrorOutcome {
            $rows = [];
            $unmapped = [];

            foreach ($access->principals as $principal) {
                $subject = $this->resolver->resolve($principal, $tenantId);

                if ($subject === null) {
                    $unmapped[] = $principal;

                    continue;
                }

                // Keyed so a source naming the same person twice - via their
                // own address and via a group they belong to - does not
                // attempt two identical rows.
                $key = $subject->subjectType.'|'.$subject->subjectId.'|'.$principal->effect;

                $rows[$key] = [
                    'tenant_id' => $tenantId,
                    'knowledge_document_id' => $document->getKey(),
                    'subject_type' => $subject->subjectType,
                    'subject_id' => $subject->subjectId,
                    'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
                    'effect' => $principal->isDeny()
                        ? KnowledgeDocumentAcl::EFFECT_DENY
                        : KnowledgeDocumentAcl::EFFECT_ALLOW,
                    'origin' => KnowledgeDocumentAcl::ORIGIN_SOURCE_MIRROR,
                ];
            }

            $revoked = $this->clearMirroredRows($document, $tenantId);

            foreach ($rows as $row) {
                KnowledgeDocumentAcl::query()->create($row);
            }

            $this->syncQueue($document, $tenantId, $unmapped);

            // Recorded on the DOCUMENT, not inferred from the rows above.
            // A complete list naming only people this application cannot
            // place produces zero rows, and reading that as "unrestricted"
            // would leave precisely those documents open to the whole
            // project.
            $this->markRestricted($document, $tenantId, true);

            $granted = count(array_filter(
                $rows,
                static fn (array $r): bool => $r['effect'] === KnowledgeDocumentAcl::EFFECT_ALLOW,
            ));

            return MirrorOutcome::applied(
                granted: $granted,
                denied: count($rows) - $granted,
                revoked: $revoked,
                unmapped: count($unmapped),
            );
        });
    }

    /**
     * Why this pass must not touch the mirror, or null when it may.
     */
    private function reasonToSkip(SourceAccess $access): ?string
    {
        if (! $access->complete) {
            // Truncated, rate-limited, or partly refused. Acting on it would
            // revoke access because a request failed.
            return 'incomplete';
        }

        if ($access->inheritsFromParent) {
            // "As the folder says" is a pointer, not a list, and this
            // application does not model the folder. Reading the empty
            // principals as "nobody" would unshare the document.
            return 'inherited';
        }

        return null;
    }

    /**
     * Delete every mirrored row for this document, leaving manual ones alone.
     */
    private function clearMirroredRows(KnowledgeDocument $document, string $tenantId): int
    {
        return KnowledgeDocumentAcl::query()
            ->where('tenant_id', $tenantId)
            ->where('knowledge_document_id', $document->getKey())
            ->where('origin', KnowledgeDocumentAcl::ORIGIN_SOURCE_MIRROR)
            ->delete();
    }

    /**
     * Bring the triage queue in line with what the source reported.
     *
     * Surviving entries keep their status, so a principal an operator has
     * already dismissed does not come back as new work on every sync;
     * entries the source no longer names are removed, because the question
     * they represented is no longer being asked.
     *
     * @param  list<SourcePrincipal>  $unmapped
     */
    private function syncQueue(KnowledgeDocument $document, string $tenantId, array $unmapped): void
    {
        $keep = [];

        foreach ($unmapped as $principal) {
            $externalId = mb_substr($principal->externalId, 0, 320);
            $key = $principal->type.'|'.$externalId;
            $keep[] = $key;

            $existing = UnmappedSourcePrincipal::query()
                ->where('tenant_id', $tenantId)
                ->where('knowledge_document_id', $document->getKey())
                ->where('principal_type', $principal->type)
                ->where('principal_external_id', $externalId)
                ->first();

            if ($existing !== null) {
                // first_seen_at is never moved, so the queue shows how long a
                // question has gone unanswered, and status is left alone so a
                // decision taken once is not re-asked on the next sync.
                $existing->forceFill([
                    'project_key' => $document->project_key,
                    'effect' => $principal->effect,
                    'last_seen_at' => now(),
                ])->save();

                continue;
            }

            UnmappedSourcePrincipal::query()->create([
                'tenant_id' => $tenantId,
                'knowledge_document_id' => $document->getKey(),
                'project_key' => $document->project_key,
                'principal_type' => $principal->type,
                'principal_external_id' => $externalId,
                'effect' => $principal->effect,
                'status' => UnmappedSourcePrincipal::STATUS_PENDING,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $stale = UnmappedSourcePrincipal::query()
            ->where('tenant_id', $tenantId)
            ->where('knowledge_document_id', $document->getKey())
            ->get(['id', 'principal_type', 'principal_external_id'])
            ->reject(fn (UnmappedSourcePrincipal $row): bool => in_array(
                $row->principal_type.'|'.$row->principal_external_id,
                $keep,
                true,
            ))
            ->pluck('id')
            ->all();

        if ($stale !== []) {
            UnmappedSourcePrincipal::query()->whereIn('id', $stale)->delete();
        }
    }

    /**
     * Record whether a source currently dictates this document's readers.
     */
    private function markRestricted(KnowledgeDocument $document, string $tenantId, bool $restricted): void
    {
        $value = $restricted ? now() : null;

        // Written unscoped and without touching `updated_at`: this is a fact
        // about how the document is governed, not an edit to it, and the
        // ingest path has just written the row.
        KnowledgeDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($document->getKey())
            ->update(['source_acl_enforced_at' => $value]);

        $document->setAttribute('source_acl_enforced_at', $value);
    }

    private function clearQueue(KnowledgeDocument $document, string $tenantId): void
    {
        UnmappedSourcePrincipal::query()
            ->where('tenant_id', $tenantId)
            ->where('knowledge_document_id', $document->getKey())
            ->delete();
    }
}
