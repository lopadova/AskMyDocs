import { useState, type DragEvent, type ReactNode } from 'react';

/**
 * v8.9 — stateless drag-and-drop file picker for the upload modal.
 *
 * R15: the focusable, label-bound `<input type="file">` IS the control — drag
 * is an enhancement, never the only path. The input is visually offset (not
 * `display:none`) so it stays in the a11y tree and keyboard-reachable. R11:
 * stable testids. Controlled `(files, onAddFiles)` — the file list lives in
 * the parent (R29 stateless component).
 */

export interface UploadDropzoneProps {
    onAddFiles: (files: File[]) => void;
    disabled?: boolean;
    /** `accept` attribute for the native picker, e.g. ".md,.txt,.pdf,.docx". */
    accept?: string;
}

export function UploadDropzone({ onAddFiles, disabled = false, accept }: UploadDropzoneProps): ReactNode {
    const [dragging, setDragging] = useState(false);

    const handleDrop = (e: DragEvent<HTMLLabelElement>) => {
        e.preventDefault();
        setDragging(false);
        if (disabled) {
            return;
        }
        const files = Array.from(e.dataTransfer.files ?? []);
        if (files.length > 0) {
            onAddFiles(files);
        }
    };

    return (
        <label
            htmlFor="kb-upload-file-input"
            data-testid="kb-upload-dropzone"
            data-dragover={dragging ? 'true' : 'false'}
            onDragOver={(e) => {
                e.preventDefault();
                if (!disabled) setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={handleDrop}
            style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 6,
                padding: '22px 16px',
                border: `1.5px dashed ${dragging ? 'var(--accent, #6366f1)' : 'var(--panel-border, rgba(255,255,255,.18))'}`,
                borderRadius: 10,
                background: dragging ? 'rgba(99,102,241,.08)' : 'var(--bg-3, rgba(255,255,255,.03))',
                color: 'var(--fg-2)',
                fontSize: 12.5,
                cursor: disabled ? 'not-allowed' : 'pointer',
                opacity: disabled ? 0.6 : 1,
                textAlign: 'center',
            }}
        >
            <span aria-hidden="true" style={{ fontSize: 20 }}>⬆</span>
            <span>
                <strong style={{ color: 'var(--fg-1)' }}>Drop files here</strong> or click to browse
            </span>
            <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>Markdown, text, PDF or Word — up to 100 files</span>
            {/* Visually-offset but focusable + in the a11y tree (R15). */}
            <input
                id="kb-upload-file-input"
                data-testid="kb-upload-file-input"
                type="file"
                multiple
                accept={accept}
                disabled={disabled}
                onChange={(e) => {
                    const files = Array.from(e.target.files ?? []);
                    if (files.length > 0) {
                        onAddFiles(files);
                    }
                    // Reset so picking the same file again still fires onChange.
                    e.target.value = '';
                }}
                style={{
                    position: 'absolute',
                    width: 1,
                    height: 1,
                    padding: 0,
                    margin: -1,
                    overflow: 'hidden',
                    clip: 'rect(0,0,0,0)',
                    whiteSpace: 'nowrap',
                    border: 0,
                }}
            />
        </label>
    );
}
