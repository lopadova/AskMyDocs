# Rule: synchronize security instructions

## Identifier

`SYNC-AI-001`

The canonical detailed rules live under `.claude/rules/rule-security-*.md`.
Critical review instructions are mirrored under `.github/instructions/` for local
Copilot CLI and GitHub Copilot Code Review.

Any PR changing a security rule, ID, mandatory gate or skill must update the
corresponding Copilot mirror and the applicability matrix in
`docs/security/LORENZO_SECURITY_EXPERIENCE.md`. Run `npm run security:rules`.

The validator checks required IDs/lessons, control disposition, mirror size, R40
prompt/parser safety and CI wiring. A mirror is a concise operational subset,
not a second divergent source of truth. AskMyDocs currently has no Gemini instruction
consumer, so `.gemini/` is intentionally absent rather than maintained as dead policy.
