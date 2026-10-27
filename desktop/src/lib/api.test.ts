import { beforeEach, expect, it, vi } from 'vitest';
import { fetch } from '@tauri-apps/plugin-http';
import { ApiError, fetchKnowledgeTree } from './api';
vi.mock('@tauri-apps/plugin-http', () => ({ fetch: vi.fn() }));
beforeEach(() => vi.clearAllMocks());
it('sends tenant, project, and Bearer context to the reader API', async () => {
  vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ tree: [], counts: { docs: 0, canonical: 0, trashed: 0 } })));
  await fetchKnowledgeTree('test-token', 'tenant-a', 'project & one');
  const [url, options] = vi.mocked(fetch).mock.calls[0];
  expect(new URL(String(url)).searchParams.get('project')).toBe('project & one');
  expect(options?.headers).toMatchObject({ Authorization: 'Bearer test-token', 'X-Tenant-Id': 'tenant-a', 'X-Project-Key': 'project & one' });
});
it('preserves API errors so the UI can explain old servers or denied access', async () => {
  vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ message: 'Access denied' }), { status: 403 }));
  await expect(fetchKnowledgeTree('token', 'tenant-a', 'project')).rejects.toMatchObject({ status: 403, message: 'Access denied' } satisfies Partial<ApiError>);
});
