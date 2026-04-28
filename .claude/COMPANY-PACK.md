# Padosoft Company Claude Pack — index

Material below was imported from `AskMyDocs-v4-private/claude/` (the
generalized, project-agnostic Padosoft Claude repo). It is **non-binding
defaults** — the project's own `CLAUDE.md` (at the repo root) and project-
specific skills under `.claude/skills/` always win when they conflict.

## What's auto-discovered (no action needed)

- **Skills** under `.claude/skills/<name>/SKILL.md` — auto-loaded by Claude Code via the `Skill` tool.
- **Agents** under `.claude/agents/<name>.md` — auto-loaded by the `Agent` tool.
- **Commands** under `.claude/commands/<name>.md` — exposed as `/<name>`.

## What needs manual reference (read on demand)

- **Cross-stack rules**: `.claude/rules/*.md` — language-agnostic best practices.
- **Laravel rules**: `.claude/rules/laravel/*.md` — applies when editing PHP/Laravel code.
- **Playwright rules**: `.claude/rules/playwright/*.md` — applies when editing E2E specs.
- **Cross-stack guidelines**: `.claude/COMPANY-CLAUDE.md` — generalized CLAUDE.md (loose default; project root CLAUDE.md overrides).
- **Laravel pattern adoption**: `.claude/COMPANY-laravel-CLAUDE.md` + `.claude/COMPANY-laravel-PATTERN-ADOPTION.md`.
- **Playwright pack**: `.claude/COMPANY-playwright-CLAUDE.md`.
- **Source attribution**: `.claude/COMPANY-INVENTORY.md` (which files were generalized vs excluded).

## Imported components (12 skills + 2 agents + 6 commands)

**Skills imported** (each has its own SKILL.md):
- `review-pr-comments` (root)
- `admin-interface-backend`, `admin-interface-component-audit`, `admin-interface-frontend` (laravel)
- `create-admin-interface`, `create-controller`, `create-crud-backend` (laravel)
- `create-filesystem-helpers`, `create-api-endpoint`, `create-service`, `create-test` (laravel)
- `playwright-enterprise-tester` (playwright)

**Agents imported**:
- `admin-interface-architect.md` (laravel)
- `playwright-enterprise-tester.md` (playwright)

**Commands imported**:
- `pagespeed-review` (root)
- `create-job`, `create-setting`, `domain-scaffold`, `domain-service` (laravel)
- `playwright-tester` (playwright)

## Updating this pack

The source of truth is `AskMyDocs-v4-private/claude/`. To refresh, re-run the
PowerShell mirror script that originally installed the pack — it skips files
that already exist in the destination, so project-specific overrides survive.
