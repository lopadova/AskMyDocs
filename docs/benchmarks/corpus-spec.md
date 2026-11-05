# W00.01 — synthetic benchmark corpus specification

Status: `SPECIFICATION_ONLY`

The eventual corpus is synthetic and public-safe. Each case must be a JSON object with:

```json
{
  "id": "q-0001",
  "track": "default|comparative|adversarial|action",
  "language": "it|en",
  "question": "synthetic question",
  "source_ids": ["doc-001"],
  "expected": {"answerable": true, "citations": ["doc-001"]},
  "policy": {"tenant": "tenant-a", "project": "project-a", "principal": "viewer"},
  "provenance": {"author": "synthetic", "review": "pending", "generator": "w00.01"},
  "split": "development|holdout"
}
```

Required minimums before W00.02 can start:

- 120 curated IT/EN questions covering answerable, unanswerable, contradictory, multi-hop, table and OCR cases.
- 40 adversarial cases covering ACL, prompt injection and data egress.
- 20 tool/action cases covering approval, timeout, duplicate and denial outcomes.
- Leakage-safe development/holdout split and second review for ambiguous labels.
- Public-safe synthetic documents only; no customer content in Git.

The corpus is deliberately not generated with placeholder labels in this PR. A case counts only after its source, expected effect, reviewer and split are recorded.
