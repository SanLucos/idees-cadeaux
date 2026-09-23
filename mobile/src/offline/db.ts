import { defineStore } from 'pinia';
import { markRaw } from 'vue';
import { DOC_TYPES, type Doc, type DocType, type LocalStore, type OutboxOp } from './localStore';
import { MemoryStore } from './memoryStore';

type DocMap = Record<DocType, Record<string, Doc>>;

const emptyDocs = (): DocMap => Object.fromEntries(DOC_TYPES.map((t) => [t, {}])) as unknown as DocMap;

/**
 * The device's copy of everything synced (spec §8), mirrored in memory
 * for the screens and written through to the LocalStore (SQLite).
 * `revision` moves on every change: screens watch it to re-read.
 */
export const useLocalDb = defineStore('localDb', {
  state: () => ({
    docs: emptyDocs(),
    outbox: [] as OutboxOp[],
    meta: {} as Record<string, string>,
    revision: 0,
    ready: false,
    store: markRaw(new MemoryStore()) as LocalStore,
  }),
  actions: {
    async open(store: LocalStore): Promise<void> {
      this.store = markRaw(store);
      const snapshot = await store.load();
      const docs = emptyDocs();
      for (const type of DOC_TYPES) {
        for (const doc of snapshot.docs[type] ?? []) docs[type][doc.id] = doc;
      }
      this.docs = docs;
      this.outbox = snapshot.outbox;
      this.meta = snapshot.meta;
      this.ready = true;
      this.revision++;
    },

    get<T extends Doc = Doc>(type: DocType, id: string | null | undefined): T | null {
      return id ? ((this.docs[type][id] as T | undefined) ?? null) : null;
    },

    all<T extends Doc = Doc>(type: DocType): T[] {
      return Object.values(this.docs[type]) as T[];
    },

    async put(type: DocType, docs: Doc[]): Promise<void> {
      if (!docs.length) return;
      for (const doc of docs) this.docs[type][doc.id] = doc;
      this.revision++;
      await this.store.putDocs(type, docs);
    },

    /** Merge fields into a document (optimistic local write). */
    async patch<T extends Doc>(type: DocType, id: string, patch: Partial<T>): Promise<T | null> {
      const current = this.docs[type][id];
      if (!current) return null;
      const next = { ...current, ...patch } as T;
      await this.put(type, [next]);

      return next;
    },

    async remove(type: DocType, ids: string[]): Promise<void> {
      if (!ids.length) return;
      for (const id of ids) delete this.docs[type][id];
      this.revision++;
      await this.store.deleteDocs(type, ids);
    },

    async setMeta(key: string, value: string | null): Promise<void> {
      if (null === value) delete this.meta[key];
      else this.meta[key] = value;
      await this.store.setMeta(key, value);
    },

    async saveOp(op: OutboxOp): Promise<void> {
      const index = this.outbox.findIndex((o) => o.seq === op.seq);
      if (index >= 0) this.outbox.splice(index, 1, op);
      else this.outbox.push(op);
      this.revision++;
      await this.store.putOp(op);
    },

    async dropOp(seq: number): Promise<void> {
      this.outbox = this.outbox.filter((o) => o.seq !== seq);
      this.revision++;
      await this.store.deleteOp(seq);
    },

    /** Documents a pending write still owns: sync must not overwrite or purge them yet. */
    protectedKeys(): Set<string> {
      const keys = new Set<string>();
      for (const op of this.outbox) {
        if ('pending' === op.status) for (const e of op.entities) keys.add(`${e.type}|${e.id}`);
      }

      return keys;
    },

    /** Sign-out: purge this account's data from the device. */
    async wipe(): Promise<void> {
      this.docs = emptyDocs();
      this.outbox = [];
      this.meta = {};
      this.revision++;
      await this.store.clear();
    },
  },
});
