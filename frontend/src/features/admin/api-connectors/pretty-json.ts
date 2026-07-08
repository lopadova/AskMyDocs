/**
 * Stringify a value as pretty JSON for display, falling back to String() on a
 * cyclic/non-serialisable value. Shared by the test panel, the try modal and the
 * free-endpoint response viewer so the formatting never drifts between them.
 */
export function prettyJson(value: unknown): string {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}
