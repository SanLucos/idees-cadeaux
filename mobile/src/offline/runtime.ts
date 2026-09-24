import { App } from '@capacitor/app';
import { Network } from '@capacitor/network';
import type { PluginListenerHandle } from '@capacitor/core';
import { useLocalDb } from './db';
import { MemoryStore } from './memoryStore';
import { SqliteStore } from './sqliteStore';
import { useSync } from './sync';
import { completePendingPrefills } from './pendingPrefill';

/** Open the device database before the first screen reads it. */
export async function openLocalDb(): Promise<void> {
  const db = useLocalDb();
  try {
    const sqlite = new SqliteStore();
    await sqlite.open();
    await db.open(sqlite);
  } catch (e) {
    // Never block the app: without SQLite it still works online, in memory.
    console.error('[offline] SQLite unavailable, using memory', e);
    await db.open(new MemoryStore());
  }
}

let handles: PluginListenerHandle[] = [];
let interval: ReturnType<typeof setInterval> | null = null;
let unsubscribeAfterSync: (() => void) | null = null;

/**
 * While signed in: sync at start, when the network comes back, when the
 * app returns to the foreground, and every minute (spec §8).
 */
export async function startSyncLoop(): Promise<void> {
  if (interval) return;
  const sync = useSync();

  sync.setOnline((await Network.getStatus()).connected);
  handles = [
    await Network.addListener('networkStatusChange', ({ connected }) => sync.setOnline(connected)),
    await App.addListener('resume', () => sync.schedule(0)),
  ];
  interval = setInterval(() => sync.schedule(0), 60_000);
  // Links saved offline get their pre-fill once a sync went through (spec §5.6).
  unsubscribeAfterSync = sync.$onAction(({ name, after }) => {
    if ('run' === name) after(() => void (sync.online && !sync.lastError ? completePendingPrefills() : null));
  });
  sync.schedule(0);
}

export async function stopSyncLoop(): Promise<void> {
  if (interval) clearInterval(interval);
  interval = null;
  unsubscribeAfterSync?.();
  unsubscribeAfterSync = null;
  await Promise.all(handles.map((h) => h.remove()));
  handles = [];
}
