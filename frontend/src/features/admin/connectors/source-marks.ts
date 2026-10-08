import api from '../../../assets/connectors/api.svg?url';
import confluence from '../../../assets/connectors/confluence.svg?url';
import evernote from '../../../assets/connectors/evernote.svg?url';
import fabric from '../../../assets/connectors/fabric.svg?url';
import freshdesk from '../../../assets/connectors/freshdesk.svg?url';
import googleDrive from '../../../assets/connectors/google-drive.svg?url';
import imap from '../../../assets/connectors/imap.svg?url';
import jira from '../../../assets/connectors/jira.svg?url';
import mcp from '../../../assets/connectors/mcp.svg?url';
import notion from '../../../assets/connectors/notion.svg?url';
import onedrive from '../../../assets/connectors/onedrive.svg?url';

interface SourceMark {
    src: string;
    accent: string;
    monochrome?: boolean;
    /** Provider-owned tiles retain their original shape and background. */
    tile?: boolean;
}

/** Bundled assets survive vendor:publish from older connector releases. */
const MARKS: Record<string, SourceMark> = {
    api: { src: api, accent: '#8B5CF6' },
    confluence: { src: confluence, accent: '#1868DB', tile: true },
    evernote: { src: evernote, accent: '#00A82D' },
    fabric: { src: fabric, accent: '#9A9AA8', monochrome: true },
    freshdesk: { src: freshdesk, accent: '#00AC4C' },
    'google-drive': { src: googleDrive, accent: '#4285F4' },
    imap: { src: imap, accent: '#6366F1' },
    jira: { src: jira, accent: '#1868DB', tile: true },
    mcp: { src: mcp, accent: '#9A9AA8', monochrome: true },
    notion: { src: notion, accent: '#9A9AA8', monochrome: true },
    onedrive: { src: onedrive, accent: '#0078D4' },
};

export function sourceMark(key: string): SourceMark | undefined {
    return Object.hasOwn(MARKS, key) ? MARKS[key] : undefined;
}

/** Preserve explicit custom icons; replace only the conventional published URL. */
export function isPublishedSourceIcon(key: string, url: string): boolean {
    try {
        return new URL(url, 'https://askmydocs.test').pathname === `/connectors/${key}.svg`;
    } catch {
        return false;
    }
}
