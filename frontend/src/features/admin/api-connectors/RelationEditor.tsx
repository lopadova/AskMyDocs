import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import type {
    ApiRoute,
    ApiRouteRelation,
    ApiRouteSummary,
    RelationFieldMap,
    RelationPayload,
} from './api-connectors.api';
import { detailLlmParamNames, listItemFieldNames } from './relation-fields';
import {
    buttonStyle,
    errorTextStyle,
    fieldCaptionStyle,
    fieldLabelStyle,
    inputStyle,
    modalBackdropStyle,
    modalPanelStyle,
} from './styles';

/**
 * Create / edit a List → Detail relation. The operator picks a LIST route and a
 * DETAIL route (of the same connector) and maps list-item fields onto detail
 * parameters. Field suggestions come from the fetched full routes:
 *   - `from` datalist = the list item's field names (output_schema @ items_path);
 *   - `to_param` datalist = the detail route's LLM parameter names.
 *
 * Presentational: it owns the form state and delegates the fetch of the selected
 * routes to the parent (onSelectListRoute / onSelectDetailRoute) so it stays
 * unit-testable without a query context.
 *
 * R11/R29 testids `api-route-relation-form-*`. R15: bound labels, role=dialog,
 * Esc closes, field errors inline. R14: submit error surfaces loudly.
 */

export interface RelationEditorProps {
    routes: ApiRouteSummary[];
    relation?: ApiRouteRelation | null;
    listRouteFull?: ApiRoute | null;
    detailRouteFull?: ApiRoute | null;
    onSelectListRoute: (routeId: number | null) => void;
    onSelectDetailRoute: (routeId: number | null) => void;
    onSubmit: (payload: RelationPayload) => void;
    onClose: () => void;
    submitError?: string | null;
    isSubmitting?: boolean;
}

interface MapRow extends RelationFieldMap {
    _key: string;
}

let mapKeySeq = 1;

function toRows(fieldMap: RelationFieldMap[] | undefined): MapRow[] {
    if (!fieldMap || fieldMap.length === 0) {
        return [{ _key: `map-${mapKeySeq++}`, from: '', to_param: '' }];
    }
    return fieldMap.map((m) => ({ _key: `map-${mapKeySeq++}`, from: m.from, to_param: m.to_param }));
}

export function RelationEditor({
    routes,
    relation,
    listRouteFull,
    detailRouteFull,
    onSelectListRoute,
    onSelectDetailRoute,
    onSubmit,
    onClose,
    submitError,
    isSubmitting,
}: RelationEditorProps): ReactNode {
    const isEdit = !!relation;
    const [listRouteId, setListRouteId] = useState<string>(
        relation ? String(relation.list_route_id) : '',
    );
    const [detailRouteId, setDetailRouteId] = useState<string>(
        relation ? String(relation.detail_route_id) : '',
    );
    const [name, setName] = useState(relation?.name ?? '');
    const [rows, setRows] = useState<MapRow[]>(() => toRows(relation?.field_map));

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const listRoutes = routes.filter((r) => r.endpoint_type === 'list');
    const detailRoutes = routes.filter((r) => r.endpoint_type === 'detail');
    const itemFields = listRouteFull ? listItemFieldNames(listRouteFull) : [];
    const detailParams = detailRouteFull ? detailLlmParamNames(detailRouteFull) : [];

    function selectList(value: string) {
        setListRouteId(value);
        onSelectListRoute(value === '' ? null : Number.parseInt(value, 10));
    }

    function selectDetail(value: string) {
        setDetailRouteId(value);
        onSelectDetailRoute(value === '' ? null : Number.parseInt(value, 10));
    }

    function updateRow(key: string, patch: Partial<MapRow>) {
        setRows((rs) => rs.map((r) => (r._key === key ? { ...r, ...patch } : r)));
    }

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const fieldMap: RelationFieldMap[] = rows
            .map((r) => ({ from: r.from.trim(), to_param: r.to_param.trim() }))
            .filter((r) => r.from !== '' && r.to_param !== '');

        onSubmit({
            list_route_id: listRouteId === '' ? 0 : Number.parseInt(listRouteId, 10),
            detail_route_id: detailRouteId === '' ? 0 : Number.parseInt(detailRouteId, 10),
            field_map: fieldMap,
            name: name.trim() === '' ? null : name.trim(),
        });
    };

    const titleId = 'api-route-relation-form-title';

    return (
        <div
            data-testid="api-route-relation-form-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={modalBackdropStyle()}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isSubmitting}
                data-testid="api-route-relation-form"
                data-state={isSubmitting ? 'loading' : 'idle'}
                onSubmit={handleSubmit}
                style={modalPanelStyle(640)}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    {isEdit ? 'Edit relation' : 'New list → detail relation'}
                </h2>

                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                    <label htmlFor="api-route-relation-form-list_route" style={{ ...fieldLabelStyle(), flex: '1 1 240px' }}>
                        <span style={fieldCaptionStyle()}>
                            List route<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                        </span>
                        <select
                            id="api-route-relation-form-list_route"
                            data-testid="api-route-relation-form-list_route"
                            value={listRouteId}
                            onChange={(e) => selectList(e.target.value)}
                            style={inputStyle()}
                        >
                            <option value="">Select a list endpoint…</option>
                            {listRoutes.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name} ({r.slug})
                                </option>
                            ))}
                        </select>
                    </label>

                    <label htmlFor="api-route-relation-form-detail_route" style={{ ...fieldLabelStyle(), flex: '1 1 240px' }}>
                        <span style={fieldCaptionStyle()}>
                            Detail route<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                        </span>
                        <select
                            id="api-route-relation-form-detail_route"
                            data-testid="api-route-relation-form-detail_route"
                            value={detailRouteId}
                            onChange={(e) => selectDetail(e.target.value)}
                            style={inputStyle()}
                        >
                            <option value="">Select a detail endpoint…</option>
                            {detailRoutes.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name} ({r.slug})
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                <datalist id="api-route-relation-item-fields">
                    {itemFields.map((f) => (
                        <option key={f} value={f} />
                    ))}
                </datalist>
                <datalist id="api-route-relation-detail-params">
                    {detailParams.map((p) => (
                        <option key={p} value={p} />
                    ))}
                </datalist>

                <div style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>
                        Field map — bind a list-item field to a detail parameter
                    </span>
                    {rows.map((row, idx) => (
                        <div
                            key={row._key}
                            data-testid={`api-route-relation-form-map-${idx}`}
                            style={{ display: 'flex', gap: 6, alignItems: 'center' }}
                        >
                            <input
                                data-testid={`api-route-relation-form-map-${idx}-from`}
                                type="text"
                                list="api-route-relation-item-fields"
                                value={row.from}
                                onChange={(e) => updateRow(row._key, { from: e.target.value })}
                                placeholder="list item field (e.g. id)"
                                aria-label={`Field map row ${idx + 1} — list item field`}
                                style={{ ...inputStyle(), fontFamily: 'var(--font-mono, monospace)' }}
                            />
                            <span aria-hidden style={{ color: 'var(--fg-3)' }}>→</span>
                            <input
                                data-testid={`api-route-relation-form-map-${idx}-to_param`}
                                type="text"
                                list="api-route-relation-detail-params"
                                value={row.to_param}
                                onChange={(e) => updateRow(row._key, { to_param: e.target.value })}
                                placeholder="detail param (e.g. id)"
                                aria-label={`Field map row ${idx + 1} — detail parameter`}
                                style={{ ...inputStyle(), fontFamily: 'var(--font-mono, monospace)' }}
                            />
                            <button
                                type="button"
                                data-testid={`api-route-relation-form-map-${idx}-remove`}
                                onClick={() => setRows((rs) => rs.filter((r) => r._key !== row._key))}
                                aria-label={`Remove field map row ${idx + 1}`}
                                style={buttonStyle('secondary', false)}
                            >
                                ✕
                            </button>
                        </div>
                    ))}
                    <button
                        type="button"
                        data-testid="api-route-relation-form-map-add"
                        onClick={() => setRows((rs) => [...rs, { _key: `map-${mapKeySeq++}`, from: '', to_param: '' }])}
                        style={{ ...buttonStyle('secondary', false), alignSelf: 'flex-start' }}
                    >
                        + Add mapping
                    </button>
                </div>

                <label htmlFor="api-route-relation-form-name" style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Label (optional)</span>
                    <input
                        id="api-route-relation-form-name"
                        data-testid="api-route-relation-form-name"
                        type="text"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="e.g. user → user detail"
                        style={inputStyle()}
                    />
                </label>

                {submitError && (
                    <p data-testid="api-route-relation-form-error" role="alert" style={errorTextStyle()}>
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="api-route-relation-form-cancel"
                        onClick={onClose}
                        style={buttonStyle('secondary', false)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid="api-route-relation-form-submit"
                        disabled={isSubmitting}
                        style={buttonStyle('primary', !!isSubmitting)}
                    >
                        {isSubmitting ? 'Saving…' : isEdit ? 'Save relation' : 'Create relation'}
                    </button>
                </div>
            </form>
        </div>
    );
}
