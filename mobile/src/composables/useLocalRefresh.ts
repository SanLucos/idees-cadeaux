import { onUnmounted, watch } from 'vue';
import type { RefresherCustomEvent } from '@ionic/vue';
import { useLocalDb } from '../offline/db';
import { useSync } from '../offline/sync';

/**
 * Screens read the device copy (spec §8): re-read whenever it moves —
 * a background sync, a queued write going through — coalescing bursts.
 */
export function useLocalRefresh(reload: () => unknown): { pullToRefresh: (event: RefresherCustomEvent) => Promise<void> } {
  const db = useLocalDb();
  let timer: ReturnType<typeof setTimeout> | null = null;
  const stop = watch(
    () => db.revision,
    () => {
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => void reload(), 60);
    },
  );
  onUnmounted(() => {
    stop();
    if (timer) clearTimeout(timer);
  });

  return {
    /** Pull-to-refresh = sync now, then re-read. */
    async pullToRefresh(event: RefresherCustomEvent): Promise<void> {
      await useSync().run(true);
      await reload();
      event.target.complete();
    },
  };
}
