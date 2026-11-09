# Stale agent runs

Inspect exact IDs first. `agent:recover-stale TENANT --ids=177,178` is a dry run;
`--apply` recovers a verified answer when present, otherwise cancels through
`AgentRunControl`, publishing the terminal event. Minimum inactivity is 60
minutes; recent events protect a run. Waiting confirmations and external tasks
are excluded. There is no automatic redispatch or age-based global cleanup.
