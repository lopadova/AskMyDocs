import { File, FileText, FileCode2, FileSpreadsheet, FileImage, FileArchive, Mail, FileAudio, FileVideo } from 'lucide-react';
import type { KbTreeDocNode } from '../admin.api';

export function fileKind(node: KbTreeDocNode): string {
    const source = node.meta.source_type?.toLowerCase() ?? '';
    const mime = node.meta.mime_type?.toLowerCase() ?? '';
    const ext = node.name.split('.').pop()?.toLowerCase() ?? '';
    if (/email|imap|gmail|outlook/.test(source) || mime === 'message/rfc822' || /^(eml|msg)$/.test(ext) || /\/connectors\/(imap|gmail|outlook)\//.test(node.path)) return 'Email';
    if (mime === 'application/pdf' || ext === 'pdf') return 'PDF';
    if (mime.startsWith('image/') || /^(png|jpg|jpeg|gif|webp|svg|heic)$/.test(ext)) return 'Image';
    if (/spreadsheet|csv|excel/.test(mime) || /^(csv|xlsx?|ods|tsv)$/.test(ext)) return 'Spreadsheet';
    if (mime.startsWith('audio/')) return 'Audio';
    if (mime.startsWith('video/')) return 'Video';
    if (/^(zip|gz|tar|7z)$/.test(ext)) return 'Archive';
    if (/^(json|xml|html|js|ts|py|php|ya?ml)$/.test(ext)) return 'Code';
    if (/^(md|markdown|txt|docx?|rtf|odt)$/.test(ext)) return 'Document';
    return 'File';
}

const icons = { Email: Mail, PDF: FileText, Image: FileImage, Spreadsheet: FileSpreadsheet, Audio: FileAudio, Video: FileVideo, Archive: FileArchive, Code: FileCode2, Document: FileText, File };
export function FileTypeIcon({ node }: { node: KbTreeDocNode }) {
    const kind = fileKind(node);
    const Glyph = icons[kind as keyof typeof icons];
    return <Glyph size={16} aria-hidden className="kb-file-icon" data-file-kind={kind} />;
}
