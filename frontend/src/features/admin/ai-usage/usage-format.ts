export const MODEL_COLORS = ['#84cc16', '#06b6a0', '#3485ff', '#d969d9', '#ffb726', '#a78bfa'];
const assignedColors = new Map<string, string>();
export function seriesColor(id: string): string {
    if (id === 'other') return '#94a3b8';
    const assigned = assignedColors.get(id);
    if (assigned) return assigned;
    let hash = 2166136261;
    for (const char of id) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619);
    hash ^= hash >>> 16;
    // Prefer the identity's hue, resolve collisions, and keep that assignment
    // across filtering, reordering and every chart in this browser session.
    const used = new Set(assignedColors.values());
    for (let offset = 0; offset < MODEL_COLORS.length; offset++) {
        const color = MODEL_COLORS[((hash >>> 0) + offset) % MODEL_COLORS.length];
        if (!used.has(color)) { assignedColors.set(id, color); return color; }
    }
    // Extend the palette for additional identities instead of reusing a marker.
    const hues = [85, 170, 215, 300, 40, 260];
    const color = `hsl(${hues[(hash >>> 0) % hues.length] + ((hash >>> 7) % 15) - 7} 65% ${46 + ((hash >>> 16) % 14)}%)`;
    assignedColors.set(id, color);
    return color;
}
export function amount(value: number, locale: string, currency: string): string {
    return new Intl.NumberFormat(locale, { style: 'currency', currency,
        minimumFractionDigits: 2, maximumFractionDigits: Math.abs(value) < .01 && value !== 0 ? 8 : 4 }).format(value);
}
export function count(value: number, locale: string): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(value);
}
export function initialDates(days = 30): { from: string; to: string } {
    const today = new Date(); today.setHours(12, 0, 0, 0);
    const date = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const to = date(today); today.setDate(today.getDate() - days + 1);
    return { from: date(today), to };
}
