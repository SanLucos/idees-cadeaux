import type { Doc, DocType, LocalSnapshot, LocalStore, OutboxOp } from './localStore';

/** A detached copy, like SQLite's JSON round trip (works on reactive proxies, unlike structuredClone). */
const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value));

/** In-memory LocalStore: unit tests, and a fallback when SQLite can't open. */
export class MemoryStore implements LocalStore {
  private docs = new Map<string, Doc>();
  private ops = new Map<number, OutboxOp>();
  private meta = new Map<string, string>();

  async load(): Promise<LocalSnapshot> {
    const docs: LocalSnapshot['docs'] = {};
    for (const [key, doc] of this.docs) {
      const type = key.split('|')[0] as DocType;
      (docs[type] ??= []).push(copy(doc));
    }

    return { docs, outbox: [...this.ops.values()].sort((a, b) => a.seq - b.seq), meta: Object.fromEntries(this.meta) };
  }

  async putDocs(type: DocType, docs: Doc[]): Promise<void> {
    for (const doc of docs) this.docs.set(`${type}|${doc.id}`, copy(doc));
  }

  async deleteDocs(type: DocType, ids: string[]): Promise<void> {
    for (const id of ids) this.docs.delete(`${type}|${id}`);
  }

  async putOp(op: OutboxOp): Promise<void> {
    this.ops.set(op.seq, copy(op));
  }

  async deleteOp(seq: number): Promise<void> {
    this.ops.delete(seq);
  }

  async setMeta(key: string, value: string | null): Promise<void> {
    if (null === value) this.meta.delete(key);
    else this.meta.set(key, value);
  }

  async clear(): Promise<void> {
    this.docs.clear();
    this.ops.clear();
    this.meta.clear();
  }
}
