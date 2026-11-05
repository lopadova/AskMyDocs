# Handoff to an external API connector authoring agent

Use [the v1 contract](../connectors/api-packages/README.md),
[JSON Schema](../connectors/api-packages/v1/connector.schema.json), and
[working example](../connectors/api-packages/v1/connector.example.json) together.
You do not need access to the AskMyDocs administration panel or its database.

## Inputs to request

Obtain the user's real API specification: absolute endpoint URLs, methods,
parameter locations, parameter types, required flags, authentication strategy,
nonsecret authentication config, representative **redacted** response shapes,
and descriptions of when each tool should be used. Ask which operations should
be exposed; do not invent write operations, auth schemes or response fields.
Obtain a destination `project_key` only when the user wants project-specific
tools. Credentials must be supplied locally at import, separately from the files.

## Deliverables

1. Produce `connector.json` using format `askmydocs.api-connector-config`, integer
   version `1`. Translate OpenAPI or prose specifications; do not package the
   original specification in place of this configuration.
2. Produce a ZIP containing `connector.json` at its root, optionally `README.md`.
   Use STORE; no enclosing folder or any other entries. Keep the ZIP under 5 MiB.
3. In README, list source specifications, endpoint purpose, assumptions and
   unresolved questions, which fields the operator must change, required secret
   **names**, and whether routes are active. Include no credential values or
   unredacted API responses.

Start from the example and replace the example hostname, paths, fields and
descriptions with evidence from the user's specification. Its two GET routes
are complete active tools that require only a separately supplied bearer token.
The example domain is illustrative; no import-time call verifies it.

Choose stable file references such as `auth-orders`, `orders-list`, `orders-detail`.
Profile refs must start with `auth-`. Each route needs a unique tool slug.
Use a full URL even when `connector.base_url` is set. Model every path placeholder
with a `path` parameter. Put operator constants in `fixed`, user-supplied arguments
in `llm`, and credential lookups in `secret`. Parameters require explicit
`type`, `required` and `sort_order` fields. `value` for fixed params is a string.

For complete specifications, set `mode: "tool"`, supply matching `input_schema`
and `tool_definition.input_schema`, describe **when** to call the tool, and set
`status: "active"` only when the user wants it available immediately. Otherwise
use `draft` and explain the missing information; never invent test success.
Generated active tools do not need a manual "Test connection" step to import.
Import preserves activation and makes no HTTP or AI calls. Runtime calls remain
subject to the installation's existing SSRF policy and operational defaults.

Include only `llm` arguments in the input schema. Mark their required names in
the schema. Describe nested response objects with ordinary JSON Schema; request
body parameters are literal top-level keys. There is no embedded code, expression
language, remote schema fetching or arbitrary request-body templating.
Omit `param_mapping`; AskMyDocs derives it from the parameters.
Do not promise bulk ingestion or automatic multi-page fetching.

Relations are optional. Connect an explicit list route to an explicit detail
route, using list-item field paths and existing detail LLM parameters. Every
reference must resolve within this file. Never include local database IDs or
tenant IDs. `project_key` is a destination-local string, not a foreign project ID.

## Packaging example

Place your generated `connector.json` and optional `README.md` in a staging
directory. Run this there; it writes only those explicit entries:

```python
import json
from pathlib import Path
from zipfile import ZipFile, ZIP_STORED

config = json.loads(Path("connector.json").read_text(encoding="utf-8"))
assert config["_meta"] == {
    "format": "askmydocs.api-connector-config", "version": 1
}
assert Path("connector.json").stat().st_size <= 5 * 1024 * 1024
with ZipFile("connector.askmydocs-api.zip", "w", compression=ZIP_STORED) as archive:
    archive.writestr("connector.json", Path("connector.json").read_bytes())
    if Path("README.md").is_file():
        assert Path("README.md").stat().st_size <= 64 * 1024
        archive.writestr("README.md", Path("README.md").read_bytes())
assert Path("connector.askmydocs-api.zip").stat().st_size <= 5 * 1024 * 1024
```

Validate against the supplied Draft 2020-12 schema with your existing validator
when available. Also check all semantic rules in the contract: references,
parameter uniqueness, secret profiles, URL placeholders and relation locations.
Schema validation alone cannot establish these cross-references or endpoint
correctness. No installation of extra validators is required by AskMyDocs.

## Operator handoff

Deliver both files plus the short README. The operator chooses **Import API** in
Connectors → API, selects JSON or ZIP, reviews the configuration and enters only
the missing credentials. For the example the preview requests
`credentials.auth-orders.token`. Do not embed that value in the deliverables.
Programmatic clients may instead POST the file to `/import/validate` and then
`/import` using the authenticated tenant context described in the contract.

Tell the operator which tools are active and which URLs/methods they will call.
After import they may exercise the existing endpoint testing controls with
their own credentials. A successful import proves configuration consistency,
not that the remote API is reachable or that its responses match the supplied
specification. No manual reconstruction of endpoints, schemas or relations is
necessary for a complete package.
