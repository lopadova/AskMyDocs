# Agent activity through Reverb

The authenticated SPA keeps one private Reverb subscription per visible
user/tenant scope. A notification contains only the run UUID, event sequence
and conversation ID. It invalidates activity and wakes a bounded event replay;
messages, tool output and permissions remain on the authorized HTTP endpoints.

`GET /agent-runs/{run}/events?transport=poll&after=N` returns at most 100 stored
events plus an optional terminal snapshot, without a PHP waiting loop. Replay
continues immediately while `has_more` is true. Otherwise the browser waits for
Reverb, with a 15-second catch-up deadline (3 seconds when disconnected).
Activity badges reconcile every 30 seconds and after notifications/reconnect.
Closed conversations open no event requests. Hiding the document disconnects
the socket and aborts replay; returning replays the same run from its cursor.
Leaving during startup does not cancel or resend the queued question.

The widget uses the same short JSON replay with a 3-second client-side wait.
The legacy SSE endpoint remains available to integrations requesting it.

## Laravel Cloud develop

Connect a managed WebSocket application to **Surface / Ask My Docs / develop**.
Set `BROADCAST_CONNECTION=reverb`; Cloud supplies `REVERB_APP_ID`,
`REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT` and
`REVERB_SCHEME`. Use HTTPS/443 for the managed endpoint. The browser reads only
public connection parameters from `/agent-transport`; no Vite secrets or
rebuild per environment. Channel signing requires the current session, CSRF,
tenant membership and exact user/tenant channel ownership. CSP allows only the
configured socket origin. Self-hosted Reverb additionally requires an explicit
`REVERB_ALLOWED_ORIGINS` hostname list.

Deploy the reviewed source normally, discover Composer packages and refresh
configuration/routes. Restart long-lived queue workers so event publishers use
the new broadcaster. Agent inference remains on the existing managed queue;
Reverb handles notifications, not inference. A broadcast failure is caught after
commit, preserving the durable event and allowing HTTP catch-up.

Validate with two conversations: navigate away during startup, return during
execution, hide/show the document and reconnect networking. There should be one
WebSocket and short event requests only for the open chat, with no duplicate
turn POSTs or PHP SSE requests. Observe App memory, FPM worker count and OOM
counters over a representative period before declaring Cloud memory stable.
A local Reverb-only load probe does not establish Cloud App stability.

