import { useEffect, useState } from "react";
import { logout as apiLogout } from "./lib/api";
import { clearSession, loadSession, type Session } from "./lib/store";
import { ChatScreen } from "./screens/ChatScreen";
import { LoginScreen } from "./screens/LoginScreen";
import { SearchScreen } from "./screens/SearchScreen";
import "./App.css";

type Tab = "chat" | "search";

function App() {
  const [session, setSession] = useState<Session | null>(null);
  const [booting, setBooting] = useState(true);
  const [tab, setTab] = useState<Tab>("chat");

  useEffect(() => {
    let cancelled = false;
    loadSession().then((stored) => {
      if (cancelled) {
        return;
      }
      setSession(stored);
      setBooting(false);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleLogout() {
    if (session) {
      await apiLogout(session.token);
    }
    await clearSession();
    setSession(null);
    setTab("chat");
  }

  if (booting) {
    return (
      <div className="splash" data-testid="app-booting">
        <span className="spinner" aria-hidden="true" />
        <span>Loading…</span>
      </div>
    );
  }

  if (!session) {
    return <LoginScreen onSuccess={setSession} />;
  }

  return (
    <div className="shell" data-testid="app-shell">
      <header className="topbar">
        <div className="brand">AskMyDocs</div>
        <nav className="tabs" aria-label="Sections">
          <button
            type="button"
            className={tab === "chat" ? "tab active" : "tab"}
            onClick={() => setTab("chat")}
            aria-current={tab === "chat"}
            data-testid="tab-chat"
          >
            Chat
          </button>
          <button
            type="button"
            className={tab === "search" ? "tab active" : "tab"}
            onClick={() => setTab("search")}
            aria-current={tab === "search"}
            data-testid="tab-search"
          >
            Search
          </button>
        </nav>
        <div className="topbar-end">
          <span className="user" data-testid="app-user" title={session.user.email}>
            {session.user.name}
          </span>
          <button
            type="button"
            className="btn ghost"
            onClick={handleLogout}
            data-testid="app-logout"
          >
            Sign out
          </button>
        </div>
      </header>

      <main className="content">
        {tab === "chat" ? (
          <ChatScreen token={session.token} />
        ) : (
          <SearchScreen token={session.token} />
        )}
      </main>
    </div>
  );
}

export default App;
