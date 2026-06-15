# AskMyDocs documentation site (Mintlify)

This folder is the **public documentation site** for AskMyDocs, published with
[Mintlify](https://mintlify.com). It is intentionally separate from the internal
engineering docs in [`/docs`](../docs) (ADRs, plans, audits): `/docs-site` is the
curated, end-user-facing reference, authored at senior-architect depth.

## Local preview

```bash
npm i -g mint        # one-time: the Mintlify CLI
cd docs-site
mint dev             # http://localhost:3000
```

`mint dev` renders the site from `docs.json` + the `*.mdx` pages and reports
broken links.

## Layout

- `docs.json` — site config + the groups-based navigation. **Every page must be
  registered here**, and Mintlify errors on a nav entry whose `.mdx` file does
  not exist.
- `*.mdx` / `architecture/*.mdx` — one file per page.
- `favicon.svg` — site favicon.

## Deployment

The Mintlify GitHub App is connected to this repository with the content
directory set to `docs-site/`. Every push to `main` that touches `docs-site/`
**auto-deploys** to the live site.

## Authoring standard

Pages follow the deep-doc template (motivation → theory → design **with a Mermaid
diagram** → data model → ADR-style rationale → worked example → gotchas). New
capabilities and README changes MUST ship their matching deep page here
(**R45 — doc-site parity** in `CLAUDE.md`). See the
[`mintlify-doc-authoring`](../.claude/skills/mintlify-doc-authoring/SKILL.md)
skill for the full contract.
