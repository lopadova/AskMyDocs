// Shared markdown renderer for the document modal AND the chat answers.
// react-markdown renders to React nodes (no dangerouslySetInnerHTML) and, with
// raw HTML disabled by default, is safe for arbitrary KB content. remark-gfm
// adds the GFM surface KB docs use: tables, task lists, strikethrough,
// autolinks.
import ReactMarkdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";
import { openUrl } from "@tauri-apps/plugin-opener";

interface Props {
  content: string;
  /** testid on the rendered container so E2E/Vitest can target the output. */
  testId?: string;
}

// Inside a Tauri webview a normal <a> click would navigate the WHOLE app away
// to the target — there is no browser chrome to go back. So: external http(s)
// links open in the system browser via the opener plugin; relative targets and
// wikilinks (not resolvable inside the desktop client) render as inert styled
// text rather than a navigating anchor.
const components: Components = {
  a({ href, children }) {
    const external = typeof href === "string" && /^https?:\/\//i.test(href);
    if (!external || !href) {
      return <span className="md-link-inert">{children}</span>;
    }
    return (
      <a
        href={href}
        className="md-link"
        onClick={(event) => {
          event.preventDefault();
          void openUrl(href);
        }}
      >
        {children}
      </a>
    );
  },
};

export function Markdown({ content, testId }: Props) {
  return (
    <div className="md" data-testid={testId}>
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>
        {content}
      </ReactMarkdown>
    </div>
  );
}
