/** Stable compatibility profiles; operators can supply a different version. */
export const USER_AGENT_PRESETS = [
    {
        id: 'chrome',
        label: 'Chrome (Windows)',
        value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    },
    {
        id: 'firefox',
        label: 'Firefox (Windows)',
        value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
    },
    {
        id: 'safari',
        label: 'Safari (macOS)',
        value: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
    },
    { id: 'askmydocs', label: 'AskMyDocs', value: 'AskMyDocs/1.0' },
] as const;

export const DEFAULT_API_USER_AGENT = USER_AGENT_PRESETS[0].value;

export function readUserAgent(headers: Record<string, string>): string {
    return Object.entries(headers).find(([name]) => name.toLowerCase() === 'user-agent')?.[1] ?? '';
}

export function withoutUserAgent(headers: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(headers).filter(([name]) => name.toLowerCase() !== 'user-agent'));
}
