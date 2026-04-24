---
name: react-effect-sync-cached-state
description: When a React component owns cached state in an imperative library (CodeMirror EditorView, canvas, D3, monaco) or a ref mirroring server state, every effect branch that re-reads the source must also sync the cached copy. Optimistic updates stay cached until refetch resolves. NaN-derived values are guarded before equality checks. `.map()` of multi-element rows wraps in a KEYED `<Fragment>` — the key on an inner element does not satisfy React. Trigger when editing components that host imperative editors (SourceTab), chat mutations, URL-param-driven activeId state, or any table/list where `.map()` returns multiple siblings per iteration.
---

# React effect sync cached state

## Rule

When internal state mirrors external state, every branch that changes
the external source must sync the mirror in the SAME branch.
Specifically:

1. **Imperative editors** (CodeMirror, Monaco, canvas, D3) — when the
   server refetch fires, dispatch the new content into the editor.
2. **Optimistic updates** stay in the cache UNTIL the refetch resolves
   — guard the cleanup on `isFetching`, not `isSuccess`.
3. **Derived values that can be `NaN`** are guarded before the
   equality check (`Number.isFinite(x) && x !== activeId`).
4. **`.map()` of multi-element rows** wraps siblings in
   `<Fragment key={id}>`, NOT `<>` with key-on-inner-child. React
   needs the key on the list element, not a nested one.

## Symptoms in a review diff

- `useEffect(() => { if (data) savedRef.current = data; }, [data])`
  — but the imperative editor (`EditorView`, canvas, etc.) is never
  touched. The ref updates; the screen stays stale.
- `onSuccess: () => queryClient.setQueryData(...msgs.filter(m => m.id > 0))`
  — drops optimistic id=0 before refetch brings the real id=1234 in.
- `useEffect(() => { if (fromUrl !== activeId) setActive(null); }, [fromUrl, activeId])`
  when `fromUrl` can be `NaN` (from `parseInt('abc')`). `NaN !== anything` → loop.
- `{rows.map(r => <><tr key={r.id}>...</tr><tr>more</tr></>)}` — key
  on inner `<tr>`, outer Fragment unkeyed. React console screams.
- Block comment says "renders nothing while loading" but the impl
  renders loading / error UIs (that's a doc-drift bug — R9 — but also
  means the mental model behind the effect shape is wrong).

## How to detect in-code

```bash
# Ref sync without imperative-API call
rg -n 'savedRef\.current\s*=\s*data|bufferRef\.current\s*=' frontend/src/

# Optimistic filter on success
rg -n '\.filter\(.*\.id\s*[>!]\s*0\)' frontend/src/features/chat/

# NaN-prone param read
rg -n 'parseInt\(|Number\(' frontend/src/ | rg -v 'isFinite|Number\.parseInt'

# Unkeyed Fragment inside .map
rg -n '\.map\([^)]*=>\s*<>' frontend/src/
```

## Fix templates

### CodeMirror sync after refetch (PR #26 SourceTab)

```tsx
// ❌
useEffect(() => {
  if (raw.data?.content_hash) {
    savedRef.current = raw.data.content;
    bufferRef.current = raw.data.content;
    // EditorView is NEVER updated — stale content on every refetch.
  }
}, [raw.data?.content_hash]);

// ✅
useEffect(() => {
  if (!raw.data?.content_hash || !viewRef.current) return;
  savedRef.current = raw.data.content;
  bufferRef.current = raw.data.content;

  const view = viewRef.current;
  const current = view.state.doc.toString();
  if (current !== raw.data.content) {
    view.dispatch({
      changes: { from: 0, to: view.state.doc.length, insert: raw.data.content },
    });
  }
}, [raw.data?.content_hash]);
```

### Keep optimistic msg until refetch resolves (PR #20)

```tsx
// ❌
onSuccess: (resp) => {
  queryClient.setQueryData(['messages', convId], (old) =>
    (old ?? []).filter(m => m.id > 0).concat(resp)  // drops optimistic placeholder
  );
  queryClient.invalidateQueries({ queryKey: ['messages', convId] });
}

// ✅
onSuccess: (resp) => {
  // Append the real assistant reply alongside the optimistic user message.
  // The refetch triggered by invalidateQueries will replace the full list
  // with server state; until it resolves, the optimistic placeholder stays
  // visible so the user's just-sent message doesn't flicker out.
  queryClient.setQueryData(['messages', convId], (old) =>
    (old ?? []).concat(resp)
  );
  queryClient.invalidateQueries({ queryKey: ['messages', convId] });
}
```

### Guard NaN before equality (PR #20 ChatView)

```tsx
// ❌
const fromUrl = Number(params.conversationId);  // NaN if non-numeric
useEffect(() => {
  if (fromUrl !== activeId) setActive(fromUrl);  // NaN !== anything, loop
}, [fromUrl, activeId]);

// ✅
const fromUrl = Number(params.conversationId);
useEffect(() => {
  if (!Number.isFinite(fromUrl)) {
    if (activeId !== null) setActive(null);
    return;
  }
  if (fromUrl !== activeId) setActive(fromUrl);
}, [fromUrl, activeId]);
```

### Fragment key on the list element (PR #28 / PR #29)

```tsx
// ❌
{rows.map(r => (
  <>
    <tr key={r.id}>
      <td>{r.when}</td><td>{r.who}</td>
    </tr>
    <tr><td colSpan={2}>{r.note}</td></tr>
  </>
))}

// ✅ — React Fragment with a key
import { Fragment } from 'react';

{rows.map(r => (
  <Fragment key={r.id}>
    <tr>
      <td>{r.when}</td><td>{r.who}</td>
    </tr>
    <tr><td colSpan={2}>{r.note}</td></tr>
  </Fragment>
))}

// ✅ alt — wrap in <tbody key> if the table structure allows it
{rows.map(r => (
  <tbody key={r.id}>
    <tr><td>{r.when}</td><td>{r.who}</td></tr>
    <tr><td colSpan={2}>{r.note}</td></tr>
  </tbody>
))}
```

## Related rules

- R11 — observable states (`data-state`). R17 is about internal state;
  R11 is about the externally-observable surface.
- R16 — every one of these bugs shipped because the test didn't fire
  the refetch / edit / non-numeric URL that would have caught it.
- R9 — the block-comment-vs-impl drift in PR #26 / PR #30 is a R9 bug
  that often co-occurs with R17: the wrong mental model behind the
  component produces both a stale comment AND a stale ref.

## Enforcement

- React key warning fires at dev-time but is easy to miss on a CI
  list of 100 console messages. Vitest runs console.error-as-throw
  via the repo's shared setup — check `frontend/src/test-setup.ts`
  keeps that mode on.
- `copilot-review-anticipator` sub-agent grep patterns cover the
  four symptoms above.

## Counter-example

```tsx
// ❌ Every bug in one component
function BadTable({ data, activeId }: Props) {
  const savedRef = useRef(data);
  useEffect(() => { savedRef.current = data; }, [data]);  // ref-only sync

  const fromUrl = Number(useParams().id);
  useEffect(() => {
    if (fromUrl !== activeId) setActive(null);            // NaN loop
  }, [fromUrl, activeId]);

  return (
    <table>{data.rows.map(r => (
      <>                                                  {/* unkeyed Fragment */}
        <tr key={r.id}><td>{r.a}</td></tr>
        <tr><td>{r.b}</td></tr>
      </>
    ))}</table>
  );
}
```
