/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * hooks/usePolling.ts`. Verbatim copy.
 */
import { useEffect } from 'react';

export const usePolling = (run: () => void | Promise<void>, enabled: boolean, intervalSeconds: number) => {
  useEffect(() => {
    if (!enabled || intervalSeconds <= 0) {
      return;
    }

    let timeout: ReturnType<typeof setTimeout> | null = null;
    let cancelled = false;

    const scheduleNext = () => {
      timeout = setTimeout(async () => {
        if (cancelled) {
          return;
        }

        await run();
        scheduleNext();
      }, intervalSeconds * 1000);
    };

    void run();
    scheduleNext();

    return () => {
      cancelled = true;
      if (timeout !== null) {
        clearTimeout(timeout);
      }
    };
  }, [run, enabled, intervalSeconds]);
};
