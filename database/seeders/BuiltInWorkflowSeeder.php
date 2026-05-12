<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Workflow;
use App\Support\TenantContext;
use App\Support\Workflow\WorkflowPractice;
use App\Support\Workflow\WorkflowType;
use Illuminate\Database\Seeder;

/**
 * v4.7/W2 — Built-in workflow templates.
 *
 * Seeds 15 system workflows (is_system=true, user_id=null) covering
 * the AskMyDocs enterprise feature set. Idempotent on re-run: the
 * (tenant_id, title, is_system=true) tuple is the natural key.
 */
class BuiltInWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantContext::class)->current();

        foreach ($this->templates() as $template) {
            Workflow::updateOrCreate(
                [
                    'tenant_id' => $tenant,
                    'title' => $template['title'],
                    'is_system' => true,
                ],
                [
                    'user_id' => null,
                    'type' => $template['type'],
                    'prompt_md' => $template['prompt_md'],
                    'columns_config' => $template['columns_config'] ?? null,
                    'practice' => $template['practice'],
                    'is_system' => true,
                ],
            );
        }
    }

    /**
     * @return list<array{title: string, type: string, prompt_md: string, columns_config?: list<array<string, mixed>>, practice: string}>
     */
    private function templates(): array
    {
        $assistant = WorkflowType::Assistant->value;
        $tabular = WorkflowType::Tabular->value;

        $compliance = WorkflowPractice::Compliance->value;
        $engineering = WorkflowPractice::Engineering->value;
        $generic = WorkflowPractice::Generic->value;
        $legal = WorkflowPractice::Legal->value;
        $support = WorkflowPractice::Support->value;

        return [
            // 1
            [
                'title' => 'Project Status Review',
                'type' => $tabular,
                'practice' => $generic,
                'prompt_md' => 'Extract the latest project status from each document. Look for status fields, ownership, recent updates, risks, and forthcoming milestones.',
                'columns_config' => [
                    ['name' => 'Status', 'prompt' => 'Current status of the project.', 'format' => 'enum_status', 'enum_values' => ['on-track', 'at-risk', 'blocked', 'completed']],
                    ['name' => 'Owner', 'prompt' => 'Person or team responsible.', 'format' => 'person'],
                    ['name' => 'Last Update', 'prompt' => 'Date of most recent update.', 'format' => 'date'],
                    ['name' => 'Risks', 'prompt' => 'Open risks or issues.', 'format' => 'bulleted_list'],
                    ['name' => 'Next Milestone', 'prompt' => 'Next major checkpoint.', 'format' => 'text'],
                    ['name' => 'Blockers', 'prompt' => 'Current blockers.', 'format' => 'bulleted_list'],
                ],
            ],
            // 2
            [
                'title' => 'Decision Audit',
                'type' => $tabular,
                'practice' => $engineering,
                'prompt_md' => 'Surface every documented decision with its approver, date, rationale, and downstream outcome.',
                'columns_config' => [
                    ['name' => 'Decision', 'prompt' => 'The decision that was made.', 'format' => 'text'],
                    ['name' => 'Date', 'prompt' => 'When the decision was taken.', 'format' => 'date'],
                    ['name' => 'Approver', 'prompt' => 'Who approved the decision.', 'format' => 'person'],
                    ['name' => 'Rationale', 'prompt' => 'Why this option was chosen.', 'format' => 'text'],
                    ['name' => 'Alternatives Considered', 'prompt' => 'Other options weighed.', 'format' => 'text'],
                    ['name' => 'Outcome', 'prompt' => 'Known result or downstream effect.', 'format' => 'text'],
                ],
            ],
            // 3
            [
                'title' => 'Incident Postmortem Index',
                'type' => $tabular,
                'practice' => $engineering,
                'prompt_md' => 'For each incident document, extract date, severity, affected service, root cause, resolution, and follow-up actions.',
                'columns_config' => [
                    ['name' => 'Date', 'prompt' => 'Incident date.', 'format' => 'date'],
                    ['name' => 'Severity', 'prompt' => 'Severity (sev-1/sev-2/sev-3).', 'format' => 'enum_status', 'enum_values' => ['sev-1', 'sev-2', 'sev-3', 'sev-4']],
                    ['name' => 'Service', 'prompt' => 'Affected service or component.', 'format' => 'text'],
                    ['name' => 'Root Cause', 'prompt' => 'Identified root cause.', 'format' => 'text'],
                    ['name' => 'Resolution', 'prompt' => 'How the incident was resolved.', 'format' => 'text'],
                    ['name' => 'Lessons Learned', 'prompt' => 'Lessons captured for the team.', 'format' => 'text'],
                    ['name' => 'Action Items', 'prompt' => 'Follow-up tickets or actions.', 'format' => 'bulleted_list'],
                ],
            ],
            // 4
            [
                'title' => 'Compliance Checklist GDPR/AI-Act',
                'type' => $tabular,
                'practice' => $compliance,
                'prompt_md' => 'Audit each document against GDPR + EU AI Act requirements. Capture status, supporting evidence, owner, and review cadence.',
                'columns_config' => [
                    ['name' => 'Requirement', 'prompt' => 'Specific regulatory requirement.', 'format' => 'text'],
                    ['name' => 'Status', 'prompt' => 'Compliance status.', 'format' => 'enum_status', 'enum_values' => ['compliant', 'partial', 'non-compliant', 'not-applicable']],
                    ['name' => 'Evidence', 'prompt' => 'Reference supporting the status.', 'format' => 'text'],
                    ['name' => 'Owner', 'prompt' => 'Compliance owner.', 'format' => 'person'],
                    ['name' => 'Last Audit', 'prompt' => 'Date of last audit.', 'format' => 'date'],
                    ['name' => 'Next Review', 'prompt' => 'Date of next review.', 'format' => 'date'],
                ],
            ],
            // 5
            [
                'title' => 'Vendor Risk Review',
                'type' => $tabular,
                'practice' => $compliance,
                'prompt_md' => 'Summarise each vendor: service consumed, data sensitivity, contract status, DPA, security certifications, renewal date.',
                'columns_config' => [
                    ['name' => 'Vendor', 'prompt' => 'Vendor name.', 'format' => 'text'],
                    ['name' => 'Service', 'prompt' => 'Service consumed.', 'format' => 'text'],
                    ['name' => 'Data Sensitivity', 'prompt' => 'Sensitivity classification.', 'format' => 'enum_status', 'enum_values' => ['public', 'internal', 'confidential', 'restricted']],
                    ['name' => 'Contract Status', 'prompt' => 'Active / pending / expired.', 'format' => 'enum_status', 'enum_values' => ['active', 'pending', 'expired']],
                    ['name' => 'DPA Signed', 'prompt' => 'DPA signed?', 'format' => 'yes_no'],
                    ['name' => 'Security Cert', 'prompt' => 'Held certifications.', 'format' => 'text'],
                    ['name' => 'Renewal Date', 'prompt' => 'Next renewal.', 'format' => 'date'],
                ],
            ],
            // 6
            [
                'title' => 'Meeting Notes Summary',
                'type' => $assistant,
                'practice' => $generic,
                'prompt_md' => 'You are a meeting-notes assistant. Summarise the supplied meeting transcripts into bullet decisions and a numbered list of action items with owners.',
            ],
            // 7
            [
                'title' => 'Document Summary by Heading',
                'type' => $tabular,
                'practice' => $generic,
                'prompt_md' => 'For each top-level heading in the document, capture key points, open questions, and outbound references.',
                'columns_config' => [
                    ['name' => 'Heading', 'prompt' => 'Top-level heading.', 'format' => 'text'],
                    ['name' => 'Key Points', 'prompt' => 'Most important bullets.', 'format' => 'bulleted_list'],
                    ['name' => 'Open Questions', 'prompt' => 'Unresolved questions.', 'format' => 'bulleted_list'],
                    ['name' => 'References', 'prompt' => 'Outbound links / wikilinks.', 'format' => 'bulleted_list'],
                ],
            ],
            // 8
            [
                'title' => 'Architecture Decision Records Index',
                'type' => $tabular,
                'practice' => $engineering,
                'prompt_md' => 'Index every ADR in the corpus. Capture id, title, status, date, context, decision, consequences.',
                'columns_config' => [
                    ['name' => 'ADR ID', 'prompt' => 'ADR identifier.', 'format' => 'text'],
                    ['name' => 'Title', 'prompt' => 'ADR title.', 'format' => 'text'],
                    ['name' => 'Status', 'prompt' => 'Proposed / accepted / superseded / deprecated.', 'format' => 'enum_status', 'enum_values' => ['proposed', 'accepted', 'superseded', 'deprecated']],
                    ['name' => 'Date', 'prompt' => 'Decision date.', 'format' => 'date'],
                    ['name' => 'Context', 'prompt' => 'Driving context.', 'format' => 'text'],
                    ['name' => 'Decision', 'prompt' => 'The decision made.', 'format' => 'text'],
                    ['name' => 'Consequences', 'prompt' => 'Resulting consequences.', 'format' => 'text'],
                ],
            ],
            // 9
            [
                'title' => 'OKR Tracker',
                'type' => $tabular,
                'practice' => $generic,
                'prompt_md' => 'Extract objectives and their key results: owner, target value, current value, RAG status, notes.',
                'columns_config' => [
                    ['name' => 'Objective', 'prompt' => 'Parent objective.', 'format' => 'text'],
                    ['name' => 'Key Result', 'prompt' => 'Measured key result.', 'format' => 'text'],
                    ['name' => 'Owner', 'prompt' => 'KR owner.', 'format' => 'person'],
                    ['name' => 'Target', 'prompt' => 'Target value.', 'format' => 'number'],
                    ['name' => 'Current', 'prompt' => 'Current value.', 'format' => 'number'],
                    ['name' => 'Status', 'prompt' => 'Green / yellow / red.', 'format' => 'enum_status', 'enum_values' => ['green', 'yellow', 'red']],
                    ['name' => 'Notes', 'prompt' => 'Free-form context.', 'format' => 'text'],
                ],
            ],
            // 10
            [
                'title' => 'Customer Feedback Themes',
                'type' => $tabular,
                'practice' => $support,
                'prompt_md' => 'Cluster customer feedback into themes. For each theme, record mentions, sentiment, examples, priority, owner.',
                'columns_config' => [
                    ['name' => 'Theme', 'prompt' => 'Feedback theme.', 'format' => 'text'],
                    ['name' => 'Mentions', 'prompt' => 'Count of mentions.', 'format' => 'number'],
                    ['name' => 'Sentiment', 'prompt' => 'Overall sentiment.', 'format' => 'enum_status', 'enum_values' => ['positive', 'neutral', 'negative']],
                    ['name' => 'Examples', 'prompt' => 'Representative quotes.', 'format' => 'bulleted_list'],
                    ['name' => 'Priority', 'prompt' => 'P0 / P1 / P2 / P3.', 'format' => 'enum_status', 'enum_values' => ['P0', 'P1', 'P2', 'P3']],
                    ['name' => 'Owner', 'prompt' => 'Theme owner.', 'format' => 'person'],
                ],
            ],
            // 11
            [
                'title' => 'Patent Box Tracker',
                'type' => $tabular,
                'practice' => $compliance,
                'prompt_md' => 'For each IP asset documented, capture type, filing date, cumulative cost, linked revenue, allocation percentage, and current status — supports Italian Patent Box dossier compilation.',
                'columns_config' => [
                    ['name' => 'IP Asset', 'prompt' => 'IP asset name.', 'format' => 'text'],
                    ['name' => 'Type', 'prompt' => 'Patent / software / trademark / know-how.', 'format' => 'enum_status', 'enum_values' => ['patent', 'software', 'trademark', 'know-how']],
                    ['name' => 'Filing Date', 'prompt' => 'Filing date.', 'format' => 'date'],
                    ['name' => 'Cost', 'prompt' => 'Cumulative R&D cost.', 'format' => 'number'],
                    ['name' => 'Revenue Linked', 'prompt' => 'Attributable revenue.', 'format' => 'number'],
                    ['name' => 'Allocation %', 'prompt' => 'Allocation percentage.', 'format' => 'number'],
                    ['name' => 'Status', 'prompt' => 'Active / lapsed / expired.', 'format' => 'enum_status', 'enum_values' => ['active', 'lapsed', 'expired']],
                ],
            ],
            // 12
            [
                'title' => 'CP Checklist',
                'type' => $tabular,
                'practice' => $legal,
                'prompt_md' => 'For each condition precedent in the credit agreement, record its status, whether the counterparty has confirmed, supporting documentation, and notes.',
                'columns_config' => [
                    ['name' => 'Condition Precedent', 'prompt' => 'The CP under review.', 'format' => 'text'],
                    ['name' => 'Status', 'prompt' => 'Satisfied / pending / waived.', 'format' => 'enum_status', 'enum_values' => ['satisfied', 'pending', 'waived']],
                    ['name' => 'Counterparty Confirmed', 'prompt' => 'Confirmation received?', 'format' => 'yes_no'],
                    ['name' => 'Documentation Ref', 'prompt' => 'Supporting document reference.', 'format' => 'text'],
                    ['name' => 'Notes', 'prompt' => 'Free-form context.', 'format' => 'text'],
                ],
            ],
            // 13
            [
                'title' => 'Credit Agreement Summary',
                'type' => $assistant,
                'practice' => $legal,
                'prompt_md' => 'You are a credit-agreement assistant. Summarise key terms: borrower, lenders, principal, tenor, financial covenants, events of default, key conditions precedent.',
            ],
            // 14
            [
                'title' => 'Shareholder Agreement Summary',
                'type' => $assistant,
                'practice' => $legal,
                'prompt_md' => 'You are a shareholder-agreement assistant. Summarise governance (board composition, reserved matters), transfer restrictions, drag/tag rights, and exit mechanics.',
            ],
            // 15
            [
                'title' => 'PII Audit Review',
                'type' => $tabular,
                'practice' => $compliance,
                'prompt_md' => 'Audit each document for PII. Use the redactor inspector output where available: list detected PII types, counts, sensitivity, and the required action.',
                'columns_config' => [
                    ['name' => 'Document', 'prompt' => 'Document title or path.', 'format' => 'text'],
                    ['name' => 'PII Types Detected', 'prompt' => 'Inspector tags fired.', 'format' => 'text'],
                    ['name' => 'Detection Counts', 'prompt' => 'Matches per type.', 'format' => 'text'],
                    ['name' => 'Sensitivity Level', 'prompt' => 'Low / medium / high.', 'format' => 'enum_status', 'enum_values' => ['low', 'medium', 'high']],
                    ['name' => 'Action Required', 'prompt' => 'Redact / pseudonymise / encrypt / no-op.', 'format' => 'enum_status', 'enum_values' => ['redact', 'pseudonymise', 'encrypt', 'no-op']],
                    ['name' => 'Status', 'prompt' => 'Open / in-progress / done.', 'format' => 'enum_status', 'enum_values' => ['open', 'in-progress', 'done']],
                ],
            ],
        ];
    }
}
