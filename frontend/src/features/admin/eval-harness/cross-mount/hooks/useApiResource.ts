/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * hooks/useApiResource.ts`. Verbatim copy modulo relative-path imports.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiErrorState, ApiResult } from '../types/api';

type ResourceStatus = 'idle' | 'loading' | 'ready' | 'error';

type ResourceState<T> =
  | { status: 'idle'; data: undefined; error: undefined }
  | { status: 'loading'; data: undefined; error: undefined }
  | { status: 'ready'; data: T; error: undefined }
  | { status: 'error'; data: undefined; error: ApiErrorState };

type CacheEntry<T> = {
  state: 'ready' | 'error';
  data: T | undefined;
  error: ApiErrorState | undefined;
  expiryAt: number;
  inFlight?: Promise<ApiResult<T>>;
};

const cache = new Map<string, CacheEntry<unknown>>();

/**
 * Test-only escape hatch — wipes the module-level cache so vitest
 * scenarios can drive deterministic post-fetch state without
 * inheriting cache hits from previous tests in the same file. Not
 * exported from the upstream package; safe to call from production
 * code only when the SPA is genuinely tearing down (logout, etc.).
 */
export const __resetApiResourceCacheForTests = (): void => {
  cache.clear();
};

const readCache = <T,>(key: string): CacheEntry<T> | null => {
  const entry = cache.get(key);
  if (!entry) {
    return null;
  }

  return {
    state: entry.state,
    data: entry.data as T | undefined,
    error: entry.error,
    expiryAt: entry.expiryAt,
    inFlight: entry.inFlight as Promise<ApiResult<T>> | undefined,
  };
};

const writeCache = <T,>(key: string, entry: CacheEntry<T>): void => {
  cache.set(key, entry as CacheEntry<unknown>);
};

export type ApiResourceOptions = {
  cacheKey?: string;
  ttlMs?: number;
  noCache?: boolean;
};

const buildIdle = (): ResourceState<never> => ({ status: 'idle', data: undefined, error: undefined });

export const useApiResource = <T,>(
  load: () => Promise<ApiResult<T>>,
  deps: unknown[] = [],
  options?: ApiResourceOptions,
) => {
  const loadRef = useRef(load);
  const [state, setState] = useState<ResourceState<T>>(() => {
    const cacheKey = options?.cacheKey;
    const now = Date.now();
    const noCache = options?.noCache ?? false;
    if (!cacheKey || noCache) {
      return buildIdle();
    }

    const cached = readCache<T>(cacheKey);
    if (!cached) {
      return buildIdle();
    }

    if (cached.expiryAt > now && cached.inFlight === undefined && cached.state === 'ready') {
      return { status: 'ready', data: cached.data as T, error: undefined };
    }

    return buildIdle();
  });
  const ttlMs = options?.ttlMs ?? 30_000;
  const cacheKey = options?.cacheKey;
  const noCache = options?.noCache ?? false;

  const applyResultToCache = useCallback(
    (result: ApiResult<T>) => {
      if (!cacheKey || noCache) {
        return;
      }

      if (result.error) {
        writeCache(cacheKey, {
          state: 'error',
          data: undefined,
          error: result.error,
          inFlight: undefined,
          expiryAt: Date.now() + ttlMs,
        });
        return;
      }

      writeCache(cacheKey, {
        state: 'ready',
        data: result.data,
        error: undefined,
        inFlight: undefined,
        expiryAt: Date.now() + ttlMs,
      });
    },
    [cacheKey, noCache, ttlMs],
  );

  const run = useCallback(
    async (force = false) => {
      const now = Date.now();
      if (cacheKey && !noCache) {
        const cached = readCache<T>(cacheKey);
        if (!force && cached) {
          if (cached.inFlight) {
            const inFlightResult = await cached.inFlight;
            if (inFlightResult.error) {
              setState({ status: 'error', data: undefined, error: inFlightResult.error });
            } else {
              setState({ status: 'ready', data: inFlightResult.data as T, error: undefined });
            }
            return;
          }

          const fresh = cached.expiryAt > now && cached.state === 'ready';
          if (fresh) {
            setState({ status: 'ready', data: cached.data as T, error: undefined });
            return;
          }

          if (cached.state === 'error' && cached.expiryAt > now) {
            setState({ status: 'error', data: undefined, error: cached.error as ApiErrorState });
            return;
          }
        }
      }

      setState({ status: 'loading', data: undefined, error: undefined });

      const request = (async () => {
        try {
          return await loadRef.current();
        } catch (error) {
          return {
            error: {
              kind: 'error',
              message: error instanceof Error ? error.message : 'Unexpected client error',
            },
          } as ApiResult<T>;
        }
      })();

      if (cacheKey && !noCache) {
        writeCache(cacheKey, {
          state: 'error',
          data: undefined,
          error: undefined,
          inFlight: request,
          expiryAt: Number.MAX_SAFE_INTEGER,
        });
      }

      const result = await request;
      applyResultToCache(result);

      if (result.error) {
        setState({ status: 'error', data: undefined, error: result.error });
      } else {
        setState({ status: 'ready', data: result.data as T, error: undefined });
      }
    },
    [cacheKey, noCache, applyResultToCache],
  );

  useEffect(() => {
    loadRef.current = load;
  }, [load]);

  useEffect(() => {
    void run(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [run, ...deps]);

  return {
    status: state.status as ResourceStatus,
    state,
    data: state.status === 'ready' ? state.data : undefined,
    error: state.status === 'error' ? state.error : undefined,
    reload: () => run(true),
  };
};
