<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Connectors\Freshdesk\FreshdeskCaseStudyLineage;
use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyInput;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyResult;

final readonly class FreshdeskCaseStudyConverter implements ConverterInterface
{
    public function __construct(private FreshdeskCaseStudyLineage $lineage) {}

    public function name(): string
    {
        return 'freshdesk-case-study';
    }

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/vnd.askmydocs.freshdesk-case+markdown';
    }

    public function convert(SourceDocument $doc): ConvertedDocument
    {
        $project = explode('/', $doc->sourcePath, 2)[0];
        $case = $this->lineage->assertValid($doc->metadata, $project);
        $expected = (new CaseStudyResult($case->result['fields']))->markdown(CaseStudyInput::fromArray($case->snapshot));
        if (! hash_equals(hash('sha256', $expected), hash('sha256', $doc->bytes))) {
            throw new \RuntimeException('Freshdesk case file differs from its verified result.');
        }

        return new ConvertedDocument($expected, [], ['converter' => $this->name(), 'provenance' => 'freshdesk_case_study'], $doc->mimeType);
    }
}
