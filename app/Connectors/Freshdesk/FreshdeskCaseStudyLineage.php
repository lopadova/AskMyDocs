<?php

declare(strict_types=1);

namespace App\Connectors\Freshdesk;

use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudy;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyInput;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyManager;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyResult;

final class FreshdeskCaseStudyLineage
{
    public const SOURCE_TYPE = 'freshdesk_case_study';

    /** @param array<string,mixed> $metadata */
    public function assertValid(array $metadata, string $projectKey, bool $lock = false): CaseStudy
    {
        $case = $this->lookup($metadata, $lock);
        if ($case === null || ($case->snapshot['projectKey'] ?? null) !== $projectKey) {
            throw new \RuntimeException('Freshdesk case sheet is stale, unavailable or outside the current project.');
        }
        $input = CaseStudyInput::fromArray($case->snapshot);
        foreach ($input->sources as $source) {
            if (! hash_equals($source['sha256'], hash('sha256', $source['text']))) {
                throw new \RuntimeException('Freshdesk case source snapshot hash mismatch.');
            }
        }
        (new CaseStudyResult($case->result['fields']))->validate($input);

        return $case;
    }

    public function allows(KnowledgeDocument $document): bool
    {
        if ($document->source_type !== self::SOURCE_TYPE) {
            return true;
        }
        try {
            $this->assertValid((array) $document->metadata, (string) $document->project_key);

            return $document->tenant_id === app(TenantContext::class)->current();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Read the verified original quotations, not the generated summary, as answering evidence. */
    public function originalEvidence(KnowledgeDocument $document): ?string
    {
        try {
            $case = $this->assertValid((array) $document->metadata, (string) $document->project_key);
            $input = CaseStudyInput::fromArray($case->snapshot);
            $markdown = (new CaseStudyResult($case->result['fields']))->markdown($input);

            return 'Ticket Freshdesk #'.$input->ticketId.' — '.$input->title."\n".$input->sourceUrl."\n\n".explode("## Evidenze originali\n\n", $markdown, 2)[1];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $metadata */
    private function lookup(array $metadata, bool $lock): ?CaseStudy
    {
        if (! class_exists(CaseStudyManager::class)) {
            return null;
        }
        $tenants = app(PackageTenantContext::class);
        $previous = $tenants->current();
        $tenants->set(app(TenantContext::class)->current());
        try {
            return app(CaseStudyManager::class)->validMetadata($metadata, $lock);
        } finally {
            $tenants->set($previous);
        }
    }
}
