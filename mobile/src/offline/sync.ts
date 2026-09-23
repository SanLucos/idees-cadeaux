import { defineStore } from 'pinia';
import { api, isNetworkError, SessionExpiredError } from '../services/api';
import { uuidv7 } from '../utils/uuidv7';
import { useLocalDb } from './db';
import { DOC_TYPES, type Doc, type DocType, type OutboxOp } from './localStore';

const CURSOR = 'sync.cursor';
const LAST_SYNCED = 'sync.lastSyncedAt';
const FETCH_CHUNK = 500;
const MAX_BACKOFF_MS = 5 * 60_000;

interface SyncResponse {
  cursor: string;
  changes: Partial<Record<DocType, Doc[]>>;
  hashes: Partial<Record<DocType, string>>;
  manifests: Partial<Record<DocType, [string, string][]>>;
}

export type NewOp = Pick<OutboxOp, 'method' | 'path' | 'entities' | 'label'> &
  Partial<Pick<OutboxOp, 'body' | 'upload' | 'actingAs'>>;

/**
 * Spec §8 offline engine:
 * - writes are applied locally first (offline/mutations.ts), then queued
 *   here and replayed in order, each with its own Idempotency-Key, with
 *   retries and exponential backoff;
 * - a write the server refuses (e.g. « déjà réservé par X ») stays
 *   listed as failed, with the reason, until retried or dropped; the
 *   next pull restores the server's truth locally;
 * - pull (POST /sync, spec §11 décision 32): apply the delta, and for
 *   each stale inventory drop what's no longer visible and fetch what's
 *   missing.
 * UI indicators come from this store (spec §8 "indicateurs d'UI").
 */
export const useSync = defineStore('sync', {
  state: () => ({
    online: true,
    running: false,
    lastError: null as string | null,
    /** Most recent refusal, for a toast (e.g. « Déjà réservé par Hugo »). */
    lastRefusal: null as OutboxOp | null,
    timer: null as ReturnType<typeof setTimeout> | null,
  }),
  getters: {
    /** Persisted: survives restarts, so "Synchronisé" is never claimed before a real sync. */
    lastSyncedAt(): string | null {
      return useLocalDb().meta[LAST_SYNCED] ?? null;
    },
    pending(): OutboxOp[] {
      return useLocalDb().outbox.filter((op) => 'pending' === op.status);
    },
    failed(): OutboxOp[] {
      return useLocalDb().outbox.filter((op) => 'failed' === op.status);
    },
    state(): 'offline' | 'syncing' | 'error' | 'pending' | 'synced' {
      if (!this.online) return 'offline';
      if (this.running) return 'syncing';
      if (this.failed.length || this.lastError) return 'error';
      if (this.pending.length) return 'pending';
      if (!this.lastSyncedAt) return 'syncing';

      return 'synced';
    },
  },
  actions: {
    async enqueue(input: NewOp): Promise<void> {
      const db = useLocalDb();
      const seq = Math.max(0, ...db.outbox.map((o) => o.seq)) + 1;
      await db.saveOp({
        seq,
        actingAs: null,
        ...input,
        idempotencyKey: uuidv7(),
        status: 'pending',
        attempts: 0,
        nextAttemptAt: 0,
        createdAt: new Date().toISOString(),
      });
      this.schedule();
    },

    /** Debounced: a burst of taps becomes one round trip. */
    schedule(delay = 400): void {
      if (this.timer) clearTimeout(this.timer);
      this.timer = setTimeout(() => void this.run(), delay);
    },

    /** `force`: asked by the user (sync now, pull-to-refresh) — skip the backoff wait. */
    async run(force = false): Promise<void> {
      if (this.running || !this.online) return;
      if (force) {
        for (const op of useLocalDb().outbox) if ('pending' === op.status) op.nextAttemptAt = 0;
      }
      this.running = true;
      try {
        if (await this.flush()) await this.pull();
        this.lastError = null;
      } catch (e) {
        if (e instanceof SessionExpiredError) throw e;
        this.lastError = isNetworkError(e) ? null : String(e);
      } finally {
        this.running = false;
      }
    },

    /** @returns false when it had to stop (offline, server trouble): don't pull over pending writes. */
    async flush(): Promise<boolean> {
      const db = useLocalDb();
      for (const op of [...db.outbox].sort((a, b) => a.seq - b.seq)) {
        if ('pending' !== op.status) continue;
        if (op.nextAttemptAt > Date.now()) return false;

        let result;
        try {
          result = await api.send(op.method, op.path, {
            ...(op.upload ? { formData: await toFormData(op.upload) } : op.body !== undefined ? { json: op.body } : {}),
            headers: { 'Idempotency-Key': op.idempotencyKey },
            actingAs: op.actingAs,
          });
        } catch (e) {
          if (isNetworkError(e)) {
            await db.saveOp(backoff(op));

            return false;
          }
          throw e;
        }

        if (result.status < 300 || ('DELETE' === op.method && 404 === result.status)) {
          await db.dropOp(op.seq);
        } else if (result.status >= 500 || 429 === result.status) {
          await db.saveOp(backoff(op));

          return false;
        } else {
          // The server said no (conflict, validation, gone…): keep it visible, never retry blindly.
          const failed: OutboxOp = {
            ...op,
            status: 'failed',
            error: { status: result.status, code: String(result.payload.code ?? 'request.failed'), extra: result.payload },
          };
          await db.saveOp(failed);
          this.lastRefusal = failed;
        }
      }

      return true;
    },

    async pull(): Promise<void> {
      const db = useLocalDb();
      const response = await api.post<SyncResponse>('/sync', {
        json: { since: db.meta[CURSOR] ?? null, hashes: await localHashes() },
        acting: false,
      });

      const guarded = db.protectedKeys();
      const writable = (type: DocType, docs: Doc[]) => docs.filter((d) => !guarded.has(`${type}|${d.id}`));

      for (const type of DOC_TYPES) {
        await db.put(type, writable(type, response.changes[type] ?? []));
      }

      for (const type of DOC_TYPES) {
        const manifest = response.manifests[type];
        if (!manifest) continue;
        const versions = new Map(manifest);
        const local = db.all(type);

        // Gone from the inventory = no longer visible: purge it (spec §8).
        await db.remove(type, local.filter((d) => !versions.has(d.id) && !guarded.has(`${type}|${d.id}`)).map((d) => d.id));

        const missing = manifest
          .filter(([id, version]) => !guarded.has(`${type}|${id}`) && (db.get(type, id)?._version ?? '') < version)
          .map(([id]) => id);
        for (let i = 0; i < missing.length; i += FETCH_CHUNK) {
          const { documents } = await api.post<{ documents: Doc[] }>('/sync/fetch', {
            json: { type, ids: missing.slice(i, i + FETCH_CHUNK) },
            acting: false,
          });
          await db.put(type, documents);
        }
      }

      await db.setMeta(CURSOR, response.cursor);
      await db.setMeta(LAST_SYNCED, new Date().toISOString());
      // An offline start must find this copy, even if the app is killed right after.
      await db.store.flush?.();
    },

    async retry(seq: number): Promise<void> {
      const db = useLocalDb();
      const op = db.outbox.find((o) => o.seq === seq);
      if (!op) return;
      await db.saveOp({ ...op, status: 'pending', attempts: 0, nextAttemptAt: 0, error: undefined, idempotencyKey: uuidv7() });
      this.schedule(0);
    },

    /** Give up a refused write: the next pull restores the server's version locally. */
    async discard(seq: number): Promise<void> {
      await useLocalDb().dropOp(seq);
      this.schedule(0);
    },

    setOnline(online: boolean): void {
      const wasOffline = !this.online;
      this.online = online;
      if (online && wasOffline) {
        // Back online: retry now rather than waiting out the backoff.
        const db = useLocalDb();
        for (const op of db.outbox) if ('pending' === op.status) op.nextAttemptAt = 0;
        this.schedule(0);
      }
    },
  },
});

function backoff(op: OutboxOp): OutboxOp {
  const attempts = op.attempts + 1;

  return { ...op, attempts, nextAttemptAt: Date.now() + Math.min(MAX_BACKOFF_MS, 1000 * 2 ** attempts) };
}

async function toFormData(upload: NonNullable<OutboxOp['upload']>): Promise<FormData> {
  const formData = new FormData();
  formData.append(upload.field, await (await fetch(upload.dataUrl)).blob(), upload.filename);

  return formData;
}

/** Same hash as the server: SHA-256 of the sorted "id:version" lines. Pending-write docs are left out. */
export async function localHashes(): Promise<Partial<Record<DocType, string>>> {
  const db = useLocalDb();
  const guarded = db.protectedKeys();
  const hashes: Partial<Record<DocType, string>> = {};
  for (const type of DOC_TYPES) {
    const lines = db
      .all(type)
      .filter((d) => d._version && !guarded.has(`${type}|${d.id}`))
      .map((d) => `${d.id}:${d._version}`)
      .sort();
    hashes[type] = await sha256(lines.join('\n'));
  }

  return hashes;
}

async function sha256(text: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));

  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
}
