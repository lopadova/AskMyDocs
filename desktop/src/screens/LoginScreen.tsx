import { FormEvent, useState } from "react";
import { ApiError, API_BASE, requestToken } from "../lib/api";
import { saveSession, type Session } from "../lib/store";

interface Props {
  onSuccess: (session: Session) => void;
}

type State = "idle" | "loading" | "error";

export function LoginScreen({ onSuccess }: Props) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [state, setState] = useState<State>("idle");
  const [error, setError] = useState("");

  async function submit(event: FormEvent) {
    event.preventDefault();
    setState("loading");
    setError("");

    try {
      const res = await requestToken(email.trim(), password);
      const session: Session = { token: res.token, user: res.user };
      await saveSession(session);
      onSuccess(session);
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : `Network error — is the backend reachable at ${API_BASE}?`,
      );
      setState("error");
    }
  }

  return (
    <div className="auth" data-testid="login-screen">
      <form
        className="auth-card"
        onSubmit={submit}
        data-state={state}
        aria-busy={state === "loading"}
      >
        <h1 className="auth-title">AskMyDocs</h1>
        <p className="auth-subtitle">Desktop demo</p>

        <label className="field">
          <span>Email</span>
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            autoFocus
            required
            autoComplete="username"
            data-testid="login-email"
            aria-label="Email"
          />
        </label>

        <label className="field">
          <span>Password</span>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autoComplete="current-password"
            data-testid="login-password"
            aria-label="Password"
          />
        </label>

        {state === "error" && (
          <p className="error" role="alert" data-testid="login-error">
            {error}
          </p>
        )}

        <button
          type="submit"
          className="btn primary"
          disabled={state === "loading"}
          data-testid="login-submit"
        >
          {state === "loading" ? "Signing in…" : "Sign in"}
        </button>
      </form>
    </div>
  );
}
