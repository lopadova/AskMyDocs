import { describe, expect, it } from 'vitest'
import { canonicalActionDigest, canonicalActionJson } from './actionCanonicalizer'

describe('action canonicalization', () => {
  const vector: Record<string, import('./actionCanonicalizer').CanonicalValue> = { b: 2, a: { z: true, x: 'é' }, list: [3, 1] }

  it('sorts object keys and preserves list order', () => {
    expect(canonicalActionJson(vector)).toBe('{"a":{"x":"é","z":true},"b":2,"list":[3,1]}')
  })

  it('matches the PHP golden digest', async () => {
    await expect(canonicalActionDigest(vector)).resolves.toBe(
      'c6684c950a3327126868f5b1f1b60eaf24311040e378cee43c34344b13aff979',
    )
  })
})
