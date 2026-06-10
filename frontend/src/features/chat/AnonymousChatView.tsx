import { useRef, useState, type ReactNode } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { Icon } from '../../components/Icons';
import { Markdown } from '../../lib/markdown';
import { selectCurrentHash, useTeamStore } from '../../lib/team-store';
import { anonymousChatApi, type AnonymousChatAnswer, type MessageCitation } from './chat.api';
import { AnonymousChatBanner } from './AnonymousChatBanner';
import { CitationsPopover } from './CitationsPopover';
import { RefusalNotice } from './RefusalNotice';

/**
 * v8.8.3 — anonymous (authenticated, NON-persisted) chat.
 *
 * A deliberately self-contained view that posts to the stateless
 * `POST /api/kb/chat` with `anonymous: true`. The backend force-masks PII,
 * never writes a conversation/message, and logs only minimally. Turns live
 * ONLY in component memory — a refresh clears the thread by design, which is
 * the whole point of "anonymous". It reuses none of the streaming /
 * conversation machinery so toggling the feature can never destabilise the
 * normal chat path.
 *
 * R11 testids: `anonymous-chat-view`, `anonymous-chat-input`,
 * `anonymous-chat-send`, `anonymous-chat-turn-{i}`, `anonymous-chat-empty`,
 * `anonymous-chat-disabled`, `anonymous-chat-error`.
 * R15 a11y: labelled input, `aria-busy`, `role="log"` thread, focusable
 * back-link.
 */

interface AnonymousTurn {
    question: string;
    answer: AnonymousChatAnswer | null;
    error: string | null;
}

export function AnonymousChatView(): ReactNode {
    const navigate = useNavigate();
    const teamHash = useTeamStore(selectCurrentHash) ?? '';
    const [turns, setTurns] = useState<AnonymousTurn[]>([]);
    const [draft, setDraft] = useState('');
    // Sentinel at the very end of the thread — scrolled into view after a turn
    // resolves so the latest answer shows even when the thread overflows.
    const endRef = useRef<HTMLDivElement | null>(null);

    const configQuery = useQuery({
        queryKey: ['anonymous-chat-config'],
        queryFn: anonymousChatApi.config,
        staleTime: 60_000,
    });

    const sendMutation = useMutation<AnonymousChatAnswer, Error, string>({
        mutationFn: (question) => anonymousChatApi.send(question),
        onMutate: (question) => {
            setTurns((prev) => [...prev, { question, answer: null, error: null }]);
        },
        onSuccess: (answer) => {
            setTurns((prev) => patchLastTurn(prev, { answer, error: null }));
            scrollToEnd(endRef.current);
        },
        onError: (err) => {
            setTurns((prev) => patchLastTurn(prev, { answer: null, error: err.message || 'Request failed.' }));
        },
    });

    const submit = () => {
        const question = draft.trim();
        if (!question || sendMutation.isPending) {
            return;
        }
        setDraft('');
        sendMutation.mutate(question);
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit();
        }
    };

    // Internal render discriminator — `disabled` is a distinct terminal
    // landing (feature off). The DOM `data-state` attribute, however, stays
    // within the shared SPA observable contract (`loading|error|empty|ready`):
    // the disabled landing maps to `empty` (a terminal state with no content),
    // while the precise "feature off" reason is carried by the inner
    // `data-testid="anonymous-chat-disabled"` block — so cross-SPA tooling that
    // keys off `data-state` keeps working.
    const phase = configQuery.isLoading
        ? 'loading'
        : configQuery.isError
            ? 'error'
            : configQuery.data?.enabled
                ? 'ready'
                : 'disabled';
    const dataState = phase === 'disabled' ? 'empty' : phase;

    return (
        <div
            data-testid="anonymous-chat-view"
            data-state={dataState}
            style={{ display: 'flex', flexDirection: 'column', height: '100%', minWidth: 0 }}
        >
            <header
                data-testid="anonymous-chat-header"
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 12,
                    padding: '12px 18px',
                    borderBottom: '1px solid var(--hairline)',
                }}
            >
                <button
                    type="button"
                    className="btn"
                    data-testid="anonymous-chat-back"
                    onClick={() => navigate({ to: '/app/$teamHash/chat', params: { teamHash } })}
                    aria-label="Back to saved chats"
                >
                    <Icon.Chevron size={13} style={{ transform: 'rotate(180deg)' }} />
                    Back
                </button>
                <h1 style={{ fontSize: 14, margin: 0, color: 'var(--fg-0)' }}>Anonymous chat</h1>
            </header>

            <div style={{ flex: 1, overflow: 'auto', padding: 18 }}>
                <div style={{ maxWidth: 760, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 14 }}>
                    {phase === 'loading' && (
                        <p data-testid="anonymous-chat-loading" style={{ color: 'var(--fg-3)', fontSize: 13 }}>
                            Checking availability…
                        </p>
                    )}

                    {phase === 'error' && (
                        <div
                            data-testid="anonymous-chat-error"
                            role="alert"
                            style={{
                                padding: '16px 18px',
                                border: '1px solid var(--err-border, rgba(220,50,50,.35))',
                                borderRadius: 10,
                                background: 'var(--err-bg, rgba(220,50,50,.06))',
                                fontSize: 13,
                                color: 'var(--err, #c0392b)',
                                lineHeight: 1.6,
                            }}
                        >
                            Could not check anonymous-chat availability. Please try again or reload
                            the page.
                        </div>
                    )}

                    {phase === 'disabled' && (
                        <div
                            data-testid="anonymous-chat-disabled"
                            role="status"
                            style={{
                                padding: '16px 18px',
                                border: '1px solid var(--panel-border)',
                                borderRadius: 10,
                                background: 'var(--bg-3, rgba(120,120,135,.08))',
                                fontSize: 13,
                                color: 'var(--fg-1)',
                                lineHeight: 1.6,
                            }}
                        >
                            Anonymous chat is currently disabled. An administrator can enable it by
                            setting <code>KB_ANONYMOUS_CHAT_ENABLED=true</code>.
                        </div>
                    )}

                    {phase === 'ready' && (
                        <>
                            <AnonymousChatBanner />

                            <div
                                data-testid="anonymous-chat-thread"
                                role="log"
                                aria-live="polite"
                                aria-busy={sendMutation.isPending}
                                style={{ display: 'flex', flexDirection: 'column', gap: 14 }}
                            >
                                {turns.length === 0 && (
                                    <p
                                        data-testid="anonymous-chat-empty"
                                        style={{ color: 'var(--fg-3)', fontSize: 13, lineHeight: 1.6 }}
                                    >
                                        Ask anything about the knowledge base. Nothing you type here is saved.
                                    </p>
                                )}
                                {turns.map((turn, i) => (
                                    <AnonymousTurnBlock key={i} index={i} turn={turn} />
                                ))}
                                {/* Sentinel scrolled into view after each turn resolves so the
                                    newest answer is visible even when the thread overflows. */}
                                <div ref={endRef} aria-hidden="true" />
                            </div>
                        </>
                    )}
                </div>
            </div>

            {phase === 'ready' && (
                <div style={{ borderTop: '1px solid var(--hairline)', padding: 14 }}>
                    <div style={{ maxWidth: 760, margin: '0 auto', display: 'flex', gap: 8, alignItems: 'flex-end' }}>
                        <label htmlFor="anonymous-chat-input" className="sr-only" style={srOnly}>
                            Anonymous question
                        </label>
                        <textarea
                            id="anonymous-chat-input"
                            data-testid="anonymous-chat-input"
                            className="input"
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder="Ask anonymously… (Enter to send)"
                            rows={2}
                            style={{ flex: 1, resize: 'vertical', minHeight: 44, fontSize: 13 }}
                        />
                        <button
                            type="button"
                            className="btn primary"
                            data-testid="anonymous-chat-send"
                            onClick={submit}
                            disabled={!draft.trim() || sendMutation.isPending}
                            aria-busy={sendMutation.isPending}
                        >
                            <Icon.Send size={13} />
                            Send
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

function AnonymousTurnBlock({ index, turn }: { index: number; turn: AnonymousTurn }): ReactNode {
    const refusal = turn.answer?.refusal_reason ?? null;
    const citations: MessageCitation[] = turn.answer?.citations ?? [];

    return (
        <div data-testid={`anonymous-chat-turn-${index}`} data-pending={turn.answer === null && turn.error === null ? 'true' : 'false'}>
            <div
                data-testid={`anonymous-chat-turn-${index}-question`}
                data-role="user"
                style={{
                    alignSelf: 'flex-end',
                    background: 'var(--bg-3)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 10,
                    padding: '8px 12px',
                    fontSize: 13,
                    color: 'var(--fg-0)',
                    marginBottom: 8,
                }}
            >
                {turn.question}
            </div>

            {turn.answer === null && turn.error === null && (
                <p data-testid={`anonymous-chat-turn-${index}-loading`} style={{ color: 'var(--fg-3)', fontSize: 13 }}>
                    Thinking…
                </p>
            )}

            {turn.error !== null && (
                <div
                    data-testid={`anonymous-chat-turn-${index}-error`}
                    role="alert"
                    style={{ padding: 10, fontSize: 12.5, color: 'var(--err)' }}
                >
                    {turn.error}
                </div>
            )}

            {turn.answer !== null && refusal !== null && (
                <RefusalNotice body={turn.answer.answer} reason={refusal} />
            )}

            {turn.answer !== null && refusal === null && (
                <div data-testid={`anonymous-chat-turn-${index}-answer`} data-role="assistant" style={{ fontSize: 13.5, lineHeight: 1.6 }}>
                    <Markdown source={turn.answer.answer} />
                    {citations.length > 0 && <CitationsPopover citations={citations} />}
                </div>
            )}
        </div>
    );
}

function patchLastTurn(turns: AnonymousTurn[], patch: Partial<AnonymousTurn>): AnonymousTurn[] {
    if (turns.length === 0) {
        return turns;
    }
    const next = turns.slice();
    next[next.length - 1] = { ...next[next.length - 1], ...patch };
    return next;
}

function scrollToEnd(el: HTMLElement | null): void {
    // `el` is the end-of-thread sentinel, so scrolling IT into view reveals the
    // newest answer regardless of thread height. `scrollIntoView` is
    // unimplemented in jsdom and absent on some older engines — guard so a
    // missing impl can never break the success path.
    if (!el || typeof el.scrollIntoView !== 'function') {
        return;
    }
    el.scrollIntoView({ block: 'end' });
}

const srOnly: React.CSSProperties = {
    position: 'absolute',
    width: 1,
    height: 1,
    padding: 0,
    margin: -1,
    overflow: 'hidden',
    clip: 'rect(0,0,0,0)',
    whiteSpace: 'nowrap',
    border: 0,
};
