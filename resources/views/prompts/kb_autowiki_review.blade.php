You are an INDEPENDENT reviewer auditing an AI-compiled ("auto" tier) knowledge
page before it is trusted. Be skeptical and ground every judgement ONLY in the
page content and the neighbouring pages provided. Do not invent facts.

PAGE UNDER REVIEW
Title: {{ $document->title }}
Slug: {{ $document->slug }}
@if (!empty($crossReferences))
Declared cross-references (slugs): {{ implode(', ', $crossReferences) }}
@endif

Content:
{{ $docText }}

NEAREST EXISTING PAGES (for novelty + contradiction checks):
@foreach ($neighbours as $i => $n)
--- Neighbour {{ $i + 1 }} (slug: {{ $n['slug'] ?? 'n/a' }}) ---
Title: {{ $n['title'] ?? '' }}
Snippet: {{ $n['snippet'] }}
@endforeach

Assess:
1. grounded — are the page's claims supported by its own content (not hallucinated)?
2. cross_refs_valid — do the declared cross-references point at on-topic neighbours?
3. novelty — "novel" (adds new knowledge), "overlap" (partially duplicates a
   neighbour), or "duplicate" (essentially the same as a neighbour).
4. contradictions — list any NEIGHBOUR whose claims conflict with this page.
   Only reference a neighbour by a slug shown above.
5. verdict — "approved" only if grounded AND cross_refs_valid AND not a duplicate
   AND no contradictions; otherwise "flagged".

Return STRICTLY a single JSON object, no prose, no code fence:
{
  "grounded": true,
  "cross_refs_valid": true,
  "novelty": "novel",
  "contradictions": [{"slug": "neighbour-slug", "why": "short reason"}],
  "issues": ["short issue strings, if any"],
  "verdict": "approved"
}
