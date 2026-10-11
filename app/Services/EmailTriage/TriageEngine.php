<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

use App\Decisions\Decisions;
use App\Decisions\DecisionState;
use App\Models\EmailTriageProfile;

/** One combined semantic decision; all prose and tags are rendered deterministically. */
final class TriageEngine
{
    public const RENDERER_VERSION = 1;

    public function __construct(private readonly TriageProfiles $profiles, private readonly AttachmentExcerpt $excerpts) {}

    public function prepare(string $body, array $manifest, string $disk): array
    {
        $coverage = ['mode' => 'email_and_attachment_introductions', 'body_truncated' => false,
            'partial' => false, 'attachments' => []];
        $state = ['email' => $body, 'attachments' => [], 'scope' => [
            'attachment_content' => 'introductory_excerpts_only', 'body_truncated' => false,
            // Reserve the longest possible count before packing the body.
            'omitted_files' => count($manifest),
        ]];
        $limit = DecisionState::maxBytes();
        if ($limit < 512) {
            throw new \RuntimeException('Decision state budget is too small for email triage.');
        }
        if (DecisionState::fromArray($state)->bytes > $limit) {
            $lo = 0;
            $hi = mb_strlen($body);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                $candidate = $this->ends($body, $mid);
                $bounded = $state;
                $bounded['email'] = $candidate;
                if (DecisionState::fromArray($bounded)->bytes <= $limit) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }
            $state['email'] = $this->ends($body, $lo);
            $coverage['body_truncated'] = $coverage['partial'] = true;
            $state['scope']['body_truncated'] = true;
        }
        $remaining = min((int) config('email-triage.attachment_budget_bytes', 8192), max(0, $limit - DecisionState::fromArray($state)->bytes));
        $savedCount = count(array_filter($manifest, fn ($f) => ($f['status'] ?? '') === 'saved'));
        foreach ($manifest as $file) {
            $entry = ['name' => $file['name'], 'status' => $file['status'] ?? 'missing'];
            if (($file['status'] ?? '') !== 'saved') {
                $entry['reason'] = $file['reason'] ?? $entry['status'];
                $coverage['partial'] = true;
                $coverage['attachments'][] = $entry;
                continue;
            }
            $excerpt = $remaining < 100 ? ['text' => '', 'reason' => 'state_budget']
                : (isset($file['excerpt']) ? ['text' => $file['excerpt'], 'reason' => $file['reason'] ?? 'cached_introductory_excerpt'] : $this->excerpts->read($disk, $file));
            $text = mb_substr((string) $excerpt['text'], 0, (int) config('email-triage.snippet_characters', 1500));
            $share = $savedCount > 0 ? intdiv($remaining, $savedCount) : 0;
            $savedCount--;
            $candidate = ['name' => mb_substr((string) $file['name'], 0, 150), 'mime' => $file['mime'] ?? '', 'excerpt' => $text];
            $originalLength = mb_strlen($text);
            while ($text !== '') {
                $candidate['excerpt'] = $text;
                $candidateBytes = DecisionState::fromArray(['file' => $candidate])->bytes;
                $next = $state;
                $next['attachments'][] = $candidate;
                if ($candidateBytes <= $share && DecisionState::fromArray($next)->bytes <= $limit) {
                    $state = $next;
                    $remaining -= $candidateBytes;
                    break;
                }
                $text = mb_substr($text, 0, max(0, mb_strlen($text) - max(1, intdiv(mb_strlen($text), 4))));
            }
            $entry['reason'] = $text === '' ? ($excerpt['text'] === '' ? $excerpt['reason'] : 'state_budget') : $excerpt['reason'];
            $entry['characters'] = mb_strlen($text);
            $entry['budget_truncated'] = mb_strlen($text) < $originalLength;
            if ($text === '' || $entry['budget_truncated']) {
                $coverage['partial'] = true;
            }
            $coverage['attachments'][] = $entry;
        }
        $state['scope']['omitted_files'] = count(array_filter($coverage['attachments'], fn ($file) => empty($file['characters'])));
        return ['state' => DecisionState::fromArray($state)->data, 'coverage' => $coverage];
    }

    public function analyze(EmailTriageProfile $profile, array $prepared): array
    {
        if ($profile->model !== config('decisions.models.jev.model')) {
            throw new \RuntimeException('The pinned triage model changed. Save a new profile before evaluating.');
        }
        $decision = Decisions::using('jev')->withState($prepared['state']);
        foreach ($this->profiles->questions($profile->rules) as $key => $question) {
            $decision = match ($question['type']) {
                'noul' => $decision->yesNo($key, $question['instructions'], $question['criteria']),
                'choice' => $decision->choice($key, $question['instructions'], $question['criteria']),
                'score' => $decision->score($key, $question['instructions'], $question['criteria']),
            };
        }
        $result = $decision->decide();
        $fields = [];
        $tags = [];
        foreach ($profile->rules as $rule) {
            if (! $rule['enabled']) {
                continue;
            }
            $answer = $result->answers[$rule['key']];
            $option = null;
            if ($rule['type'] === 'noul') {
                $p = $answer['probability_true'];
                $confidence = max($p, 1 - $p);
                if ($p >= $rule['threshold']) {
                    $option = $rule['options'][0];
                } elseif ($p <= 1 - $rule['threshold']) {
                    $option = $rule['options'][1];
                }
            } else {
                $probabilities = $answer['probabilities'];
                $index = array_search(max($probabilities), $probabilities, true);
                $confidence = min((float) $answer['confidence'], (float) max($probabilities));
                if ($confidence >= $rule['threshold']) {
                    $option = $rule['type'] === 'score' ? $rule['options'][(int) $index]
                        : collect($rule['options'])->firstWhere('key', $answer['choice']);
                }
            }
            $fields[] = ['key' => $rule['key'], 'label' => $rule['label'], 'value' => $option['key'] ?? null,
                'text' => $option['text'] ?? 'Non determinabile', 'confidence' => $confidence];
            if ($option !== null && trim((string) ($option['tag'] ?? '')) !== '') {
                $tags[] = trim($option['tag']);
            }
        }
        $tags = array_values(array_unique($tags));
        $card = "\n\n---\n\n## Scheda di triage automatica\n\n";
        $card .= 'Classificazione automatica; non fa parte del testo del mittente. Regole: versione '.$profile->revision.".\n\n";
        foreach ($fields as $field) {
            $card .= '- '.$this->plain($field['label']).': '.$this->plain($field['text'])."\n";
        }
        if ($tags !== []) {
            $card .= "\nTag di ricerca: ".implode(', ', array_map($this->plain(...), $tags)).".\n";
        }
        $card .= "\nCopertura: ".($prepared['coverage']['partial'] ? 'parziale' : 'completa nel perimetro configurato')
            .'; corpo email '.($prepared['coverage']['body_truncated'] ? 'limitato a inizio e fine' : 'completo')
            ."; degli allegati sono considerate soltanto le introduzioni.\n";
        foreach ($prepared['coverage']['attachments'] as $file) {
            $card .= '- Allegato '.$this->plain($file['name']).': '.$this->plain($file['reason'])."\n";
        }

        return ['result' => $result->toArray() + ['fields' => $fields], 'coverage' => $prepared['coverage'],
            'tags' => $tags, 'card' => $card, 'usage' => $result->usage, 'latency_ms' => $result->latencyMs];
    }

    private function ends(string $body, int $length): string
    {
        return mb_substr($body, 0, (int) ceil($length / 2))."\n[Contenuto intermedio escluso dal triage]\n"
            .($length > 1 ? mb_substr($body, -intdiv($length, 2)) : '');
    }

    private function plain(string $text): string
    {
        return str_replace(['#', '*', '`', '[', ']', '<', '>', '\\'], '', preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
