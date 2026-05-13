<?php

namespace App\Compliance;

class ProvenanceChain
{
    public function trace(array $input): array
    {
        return [
            'eval_trace_id' => $input['eval_trace_id'] ?? null,
            'retrieval' => $input['retrieval'] ?? [],
            'chunk' => $input['chunk'] ?? [],
            'document' => $input['document'] ?? [],
            'frontmatter_author' => $input['frontmatter_author'] ?? null,
        ];
    }
}
