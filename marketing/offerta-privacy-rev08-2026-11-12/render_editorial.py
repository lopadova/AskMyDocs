"""Renderer approvato con evidenziazione esplicita delle righe consigliate."""
from pathlib import Path
import importlib.util
from reportlab.platypus import Table, TableStyle

PARENT = Path(__file__).resolve().parents[1] / 'offerte-con-formazione-2026-11-12/render_editorial.py'
spec = importlib.util.spec_from_file_location('approved_editorial_renderer', PARENT)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

class RecommendedRenderer(base.Renderer):
    def table(self, p):
        self.frame(p)
        y = self.title(p) + 4
        headers, rows = p.get('headers'), p.get('rows')
        if not isinstance(headers, list) or not headers or any(not isinstance(x, str) for x in headers):
            raise base.EditorialError('table.headers must be strings')
        if not isinstance(rows, list) or any(not isinstance(r, list) or len(r) != len(headers) or any(not isinstance(x, str) for x in r) for r in rows):
            raise base.EditorialError('table.rows must match header count and contain strings')
        recommended = p.get('highlight_rows', [])
        if not isinstance(recommended, list) or any(type(n) is not int or n < 0 or n >= len(rows) for n in recommended):
            raise base.EditorialError('highlight_rows must contain valid zero based data row indices')
        weights = p.get('column_weights', [1] * len(headers))
        if not isinstance(weights, list) or len(weights) != len(headers):
            raise base.EditorialError('column_weights must match headers')
        weights = [base.positive(x, 'column weight') for x in weights]
        widths = [base.WIDTH * n / sum(weights) for n in weights]
        size = base.positive(p.get('table_font', 8.8), 'table font', 7, 20)
        pad = base.positive(p.get('padding', 6), 'table padding', 2, 20)
        cells = []
        for index, row in enumerate([headers] + rows):
            strong = index == 0 or index - 1 in recommended
            cells.append([self.paragraph(value, widths[j] - 2 * pad, size=size, leading=size + 3.3, color='cream' if strong else 'ink', variant=1 if strong else 0)[0] for j, value in enumerate(row)])
        table = Table(cells, colWidths=widths, hAlign='LEFT')
        styles = [('BACKGROUND', (0, 0), (-1, 0), self.color['primary']), ('VALIGN', (0, 0), (-1, -1), 'TOP'), ('LEFTPADDING', (0, 0), (-1, -1), pad), ('RIGHTPADDING', (0, 0), (-1, -1), pad), ('TOPPADDING', (0, 0), (-1, -1), pad), ('BOTTOMPADDING', (0, 0), (-1, -1), pad), ('ROWBACKGROUNDS', (0, 1), (-1, -1), [self.color['cream'], self.color['paper']]), ('LINEBELOW', (0, 1), (-1, -1), .35, self.color['line'])]
        for index in recommended:
            styles += [('BACKGROUND', (0, index + 1), (-1, index + 1), self.color['primary']), ('LINEABOVE', (0, index + 1), (-1, index + 1), 1, self.color['accent']), ('LINEBELOW', (0, index + 1), (-1, index + 1), 1, self.color['accent'])]
        table.setStyle(TableStyle(styles))
        _, height = table.wrap(base.W, base.H * 100)
        note_height = self.paragraph(p['note'], base.WIDTH, size=8.7, leading=12.5, color='muted')[1] if p.get('note') else 0
        if y - height < max(90, 77 + note_height + 17):
            raise base.EditorialError(f'Page {self.current}: table overflow, needs {height:.2f}pt')
        self.box('table', base.M, y - height, base.WIDTH, height, extra={'rows': len(rows), 'columns': len(headers), 'row_heights': table._rowHeights, 'highlight_rows': recommended})
        table.drawOn(self.c, base.M, y - height)
        cy = y
        for index, row in enumerate([headers] + rows):
            xx = base.M
            for j, value in enumerate(row):
                self.box('table_cell', xx, cy - table._rowHeights[index], widths[j], table._rowHeights[index], text=value, container=f'table-{self.current}')
                xx += widths[j]
            cy -= table._rowHeights[index]
        self.text(p.get('note', ''), base.M, y - height - 17, base.WIDTH, size=8.7, leading=12.5, color='muted', bottom=77, kind='table_note')

if __name__ == '__main__':
    base.Renderer = RecommendedRenderer
    raise SystemExit(base.main())
