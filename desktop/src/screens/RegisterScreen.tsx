import { FormEvent, useState } from "react";
import { ApiError, API_BASE, registerWithToken } from "../lib/api";
import { saveSession, type Session } from "../lib/store";

interface Props {
  onSuccess: (session: Session) => void;
  onNavigateLogin: () => void;
}

type State = "idle" | "loading" | "error";

// Invite-only sign-up, mirroring the web /register page but on the desktop's
// Bearer-token flow: success returns a token (not a session) which we persist
// exactly like LoginScreen.
export function RegisterScreen({ onSuccess, onNavigateLogin }: Props) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [inviteCode, setInviteCode] = useState("");
  const [state, setState] = useState<State>("idle");
  const [error, setError] = useState("");

  async function submit(event: FormEvent) {
    event.preventDefault();

    // Client-side guards mirror the server rules so the user gets instant
    // feedback before a round-trip (the server re-checks authoritatively).
    if (password.length < 8) {
      setError("Password must be at least 8 characters.");
      setState("error");
      return;
    }
    if (password !== passwordConfirmation) {
      setError("Passwords do not match.");
      setState("error");
      return;
    }

    setState("loading");
    setError("");

    try {
      const res = await registerWithToken({
        name: name.trim(),
        email: email.trim(),
        password,
        password_confirmation: passwordConfirmation,
        invite_code: inviteCode.trim(),
      });
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
    <div className="auth" data-testid="register-screen">
      <form
        className="auth-card"
        onSubmit={submit}
        data-state={state}
        aria-busy={state === "loading"}
      >
        <h1 className="auth-title">Create your account</h1>
        <p className="auth-subtitle">Sign up with your invite code</p>

        <label className="field">
          <span>Name</span>
          <input
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            autoFocus
            required
            autoComplete="name"
            data-testid="register-name"
            aria-label="Name"
          />
        </label>

        <label className="field">
          <span>Email</span>
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoComplete="username"
            data-testid="register-email"
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
            autoComplete="new-password"
            data-testid="register-password"
            aria-label="Password"
          />
        </label>

        <label className="field">
          <span>Confirm password</span>
          <input
            type="password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            required
            autoComplete="new-password"
            data-testid="register-password-confirmation"
            aria-label="Confirm password"
          />
        </label>

        <label className="field">
          <span>Invite code</span>
          <input
            type="text"
            value={inviteCode}
            onChange={(e) => setInviteCode(e.target.value)}
            required
            autoComplete="one-time-code"
            data-testid="register-invite-code"
            aria-label="Invite code"
          />
        </label>

        {state === "error" && (
          <p className="error" role="alert" data-testid="register-error">
            {error}
          </p>
        )}

        <button
          type="submit"
          className="btn primary"
          disabled={state === "loading"}
          data-testid="register-submit"
        >
          {state === "loading" ? "Creating account…" : "Create account"}
        </button>

        <p className="auth-footer">
          Already have an account?{" "}
          <button
            type="button"
            className="link"
            onClick={onNavigateLogin}
            data-testid="register-navigate-login"
          >
            Sign in
          </button>
        </p>
      </form>
    </div>
  );
}
