export type CanonicalValue = null | boolean | number | string | CanonicalValue[] | { [key: string]: CanonicalValue }

export function canonicalActionJson(value: Record<string, CanonicalValue>): string {
  return JSON.stringify(normalize(value))
}

export async function canonicalActionDigest(value: Record<string, CanonicalValue>): Promise<string> {
  const bytes = new TextEncoder().encode(canonicalActionJson(value))
  const digest = await crypto.subtle.digest('SHA-256', bytes)

  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

function normalize(value: CanonicalValue): CanonicalValue {
  if (Array.isArray(value)) return value.map(normalize)
  if (value === null || typeof value !== 'object') return value

  return Object.fromEntries(
    Object.entries(value)
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([key, child]) => [key, normalize(child)]),
  )
}
