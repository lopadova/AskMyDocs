# Project agent model profile

`ai.chat_model` is an optional tenant/project application setting. During an
owned agent run it overrides explicit text-stage models for interpretation,
planning, synthesis, presentation and repair, including isolated research
branches. The scope is restored after completion or an exception; other
projects keep their existing settings. Embeddings and typed Jev decisions
remain separately configured and separately billable.

`ai.chat_routing=economy` supplies OpenRouter with `sort=price`,
`require_parameters=true` and `max_price` from `ai.economy_max_price` (USD per
million tokens). Routing may choose another provider for the same model within
those ceilings. It does not authorize substituting another model. A missing
compatible endpoint must remain a visible error.

The Rotta/Autry local setup selected
`deepseek/deepseek-v4.1-flash-1017` at project scope. That database setting is
not a deployment migration or a global default. When releasing, apply and
verify the intended tenant/project settings explicitly, rebuild configuration
as usual, and restart long-lived workers. The source change alone does not
prove a live model run or its cost.
