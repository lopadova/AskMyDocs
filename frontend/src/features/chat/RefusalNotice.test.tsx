import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RefusalNotice } from './RefusalNotice';

describe('RefusalNotice', () => {
    it('renders the BE-localized body verbatim (no FE re-translation)', () => {
        // L22 — only the BE owns the localization; the FE renders the
        // string the BE delivered. This test pins that contract: the
        // body is passed through, NOT looked up by reason.
        render(<RefusalNotice body="No documents in the knowledge base match this question." reason="no_relevant_context" />);
        expect(screen.getByTestId('refusal-notice-body')).toHaveTextContent(
            'No documents in the knowledge base match this question.',
        );
    });

    it('renders the Italian body verbatim when the BE delivered Italian', () => {
        render(
            <RefusalNotice
                body="Nessun documento nella knowledge base corrisponde a questa domanda."
                reason="no_relevant_context"
            />,
        );
        expect(screen.getByTestId('refusal-notice-body')).toHaveTextContent(
            'Nessun documento nella knowledge base corrisponde a questa domanda.',
        );
    });

    it('exposes the refusal reason as data-reason for E2E assertions', () => {
        // Playwright covers refusal happy paths by asserting the reason
        // tag. The FE must surface it as a stable selector even though
        // the user-visible body localizes.
        render(<RefusalNotice body="Body text" reason="llm_self_refusal" />);
        expect(screen.getByTestId('refusal-notice')).toHaveAttribute(
            'data-reason',
            'llm_self_refusal',
        );
    });

    it('uses role=status + aria-live=polite (announces, never interrupts)', () => {
        // R15 — refusal is a quality signal, not an error. role=alert
        // would be too aggressive (interrupts ongoing narration).
        // role=status + aria-live=polite is the correct semantic.
        render(<RefusalNotice body="X" reason="no_relevant_context" />);
        const notice = screen.getByTestId('refusal-notice');
        expect(notice).toHaveAttribute('role', 'status');
        expect(notice).toHaveAttribute('aria-live', 'polite');
    });

    it('renders the per-reason hint for no_relevant_context', () => {
        render(<RefusalNotice body="X" reason="no_relevant_context" />);
        expect(screen.getByTestId('refusal-notice-hint')).toHaveTextContent(
            /broadening filters|adding more documents/i,
        );
    });

    it('renders the per-reason hint for llm_self_refusal', () => {
        render(<RefusalNotice body="X" reason="llm_self_refusal" />);
        expect(screen.getByTestId('refusal-notice-hint')).toHaveTextContent(
            /rephrasing the question/i,
        );
    });

    it('falls back to a generic hint for unknown reasons (forward-compat)', () => {
        // Mirror of L22: code may add a new refusal_reason before the
        // FE hint map ships. The component must still render a useful
        // string instead of leaving a blank or showing the raw key.
        render(<RefusalNotice body="X" reason="future_reason_x" />);
        const hint = screen.getByTestId('refusal-notice-hint');
        expect(hint.textContent).toBeTruthy();
        expect(hint.textContent).not.toBe('');
        // The fallback is "Try refining your question or providing more context."
        expect(hint).toHaveTextContent(/refining|context/i);
    });

    it('does NOT render any internal "kb.refusal.X" key string (no leakage)', () => {
        // The body is BE-provided so this can only fail if a future
        // change tried to do FE-side i18n lookup and forgot the
        // miss-sentinel handling. Pin it before that mistake ships.
        render(
            <RefusalNotice
                body="No documents in the knowledge base match this question."
                reason="no_relevant_context"
            />,
        );
        const notice = screen.getByTestId('refusal-notice');
        expect(notice.textContent).not.toContain('kb.refusal.');
        expect(notice.textContent).not.toContain('kb.no_grounded_answer');
    });
});
