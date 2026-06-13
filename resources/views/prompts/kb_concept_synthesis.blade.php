You are a technical knowledge-base editor. Synthesize a concise, factual
**concept page** for the recurring concept "{{ $concept }}", grounded ONLY in
the source documents below. Do not invent facts that are not supported by the
sources; if the sources are thin, keep the page short.

Source documents that reference this concept:
@foreach ($sources as $i => $src)
--- Source {{ $i + 1 }} ---
Title: {{ $src['title'] }}
@if (!empty($src['summary']))
Summary: {{ $src['summary'] }}
@endif
@endforeach

Write the page from the perspective of "what a reader needs to know about
{{ $concept }} in this knowledge base". Keep it neutral and grounded.

Return STRICTLY a single JSON object, no prose, no code fence, with exactly:
{
  "title": "A short human title for the concept (Title Case, no markdown)",
  "summary": "One or two sentences (<= 500 chars) defining the concept.",
  "body": "A concise markdown body (2-5 short paragraphs or a short list). Plain markdown only; no front-matter, no H1 title (it is added separately)."
}
