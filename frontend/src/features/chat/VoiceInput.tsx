import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';

export interface VoiceInputProps {
    onTranscript: (text: string) => void;
}

/*
 * Small wrapper around the browser's SpeechRecognition API. Falls back
 * to a disabled state when the browser doesn't support it (Firefox,
 * older Safari) — the button stays visible but announces the gap
 * via aria-disabled + tooltip.
 */
interface SpeechRecognitionLike extends EventTarget {
    continuous: boolean;
    interimResults: boolean;
    lang: string;
    start(): void;
    stop(): void;
    onresult: ((ev: SpeechRecognitionEventLike) => void) | null;
    onend: (() => void) | null;
    onerror: ((ev: { error?: string }) => void) | null;
}

interface SpeechRecognitionEventLike {
    results: ArrayLike<ArrayLike<{ transcript: string; isFinal: boolean }>>;
    resultIndex: number;
}

function getRecognitionCtor(): (new () => SpeechRecognitionLike) | null {
    const w = window as unknown as {
        SpeechRecognition?: new () => SpeechRecognitionLike;
        webkitSpeechRecognition?: new () => SpeechRecognitionLike;
    };
    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export function VoiceInput({ onTranscript }: VoiceInputProps): ReactNode {
    const [listening, setListening] = useState(false);
    const [supported, setSupported] = useState<boolean>(true);
    const recRef = useRef<SpeechRecognitionLike | null>(null);

    useEffect(() => {
        const Ctor = getRecognitionCtor();
        if (!Ctor) {
            setSupported(false);
            return;
        }
        const rec = new Ctor();
        rec.continuous = false;
        rec.interimResults = false;
        rec.lang = 'en-US';
        rec.onresult = (ev) => {
            const last = ev.results[ev.results.length - 1];
            if (last && last[0]) {
                onTranscript(last[0].transcript);
            }
        };
        rec.onend = () => setListening(false);
        rec.onerror = () => setListening(false);
        recRef.current = rec;
        return () => {
            try {
                rec.stop();
            } catch {
                /* noop */
            }
        };
    }, [onTranscript]);

    const toggle = () => {
        if (!supported || !recRef.current) {
            return;
        }
        if (listening) {
            recRef.current.stop();
            setListening(false);
            return;
        }
        try {
            recRef.current.start();
            setListening(true);
        } catch {
            setListening(false);
        }
    };

    return (
        <button
            type="button"
            onClick={toggle}
            className="btn icon sm"
            data-testid="chat-composer-voice"
            data-state={listening ? 'listening' : supported ? 'idle' : 'unsupported'}
            aria-pressed={listening}
            aria-label={listening ? 'Stop voice input' : 'Start voice input'}
            disabled={!supported}
            aria-disabled={!supported}
            title={supported ? (listening ? 'Listening…' : 'Voice input') : 'Voice input not supported in this browser'}
            style={{
                background: listening ? 'var(--grad-accent)' : 'transparent',
                border: listening ? 0 : '1px solid transparent',
                color: listening ? '#0a0a14' : supported ? 'var(--fg-2)' : 'var(--fg-3)',
                position: 'relative',
            }}
        >
            <Icon.Mic size={13} />
            {listening && (
                <span
                    aria-hidden
                    style={{
                        position: 'absolute',
                        inset: -2,
                        borderRadius: 8,
                        border: '2px solid var(--accent-a)',
                        animation: 'pulse 1.4s infinite',
                    }}
                />
            )}
        </button>
    );
}
