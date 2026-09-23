import { Capacitor } from '@capacitor/core';
import { CapacitorSQLite, SQLiteConnection, type SQLiteDBConnection } from '@capacitor-community/sqlite';
import type { Doc, DocType, LocalSnapshot, LocalStore, OutboxOp } from './localStore';

const DB_NAME = 'ideescadeaux';

const SCHEMA = `
CREATE TABLE IF NOT EXISTS docs (type TEXT NOT NULL, id TEXT NOT NULL, json TEXT NOT NULL, PRIMARY KEY (type, id));
CREATE TABLE IF NOT EXISTS outbox (seq INTEGER PRIMARY KEY NOT NULL, json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY NOT NULL, value TEXT NOT NULL);
`;

/**
 * @capacitor-community/sqlite (spec §2): native SQLite on iOS/Android;
 * in the browser, jeep-sqlite (sql.js, persisted to IndexedDB).
 */
export class SqliteStore implements LocalStore {
  private db!: SQLiteDBConnection;
  private readonly sqlite = new SQLiteConnection(CapacitorSQLite);
  private readonly web = 'web' === Capacitor.getPlatform();
  private saveTimer: ReturnType<typeof setTimeout> | null = null;

  async open(): Promise<void> {
    if (this.web) {
      const { defineCustomElements } = await import('jeep-sqlite/loader');
      await defineCustomElements(window);
      if (!document.querySelector('jeep-sqlite')) document.body.appendChild(document.createElement('jeep-sqlite'));
      await customElements.whenDefined('jeep-sqlite');
      await this.sqlite.initWebStore();
    }

    const existing = (await this.sqlite.isConnection(DB_NAME, false)).result;
    this.db = existing
      ? await this.sqlite.retrieveConnection(DB_NAME, false)
      : await this.sqlite.createConnection(DB_NAME, false, 'no-encryption', 1, false);
    await this.db.open();
    await this.db.execute(SCHEMA);
  }

  async load(): Promise<LocalSnapshot> {
    const docs: LocalSnapshot['docs'] = {};
    for (const row of (await this.db.query('SELECT type, json FROM docs')).values ?? []) {
      (docs[row.type as DocType] ??= []).push(JSON.parse(row.json));
    }
    const outbox = ((await this.db.query('SELECT json FROM outbox ORDER BY seq')).values ?? []).map((r) => JSON.parse(r.json) as OutboxOp);
    const meta: Record<string, string> = {};
    for (const row of (await this.db.query('SELECT key, value FROM meta')).values ?? []) meta[row.key] = row.value;

    return { docs, outbox, meta };
  }

  async putDocs(type: DocType, docs: Doc[]): Promise<void> {
    if (!docs.length) return;
    await this.db.executeSet(
      docs.map((doc) => ({ statement: 'INSERT OR REPLACE INTO docs (type, id, json) VALUES (?, ?, ?)', values: [type, doc.id, JSON.stringify(doc)] })),
    );
    this.persistWeb();
  }

  async deleteDocs(type: DocType, ids: string[]): Promise<void> {
    if (!ids.length) return;
    await this.db.executeSet(ids.map((id) => ({ statement: 'DELETE FROM docs WHERE type = ? AND id = ?', values: [type, id] })));
    this.persistWeb();
  }

  /**
   * A queued write is the user's action: it must survive the app being
   * killed right after, so on the web it's saved at once (natively,
   * SQLite already is durable).
   */
  async putOp(op: OutboxOp): Promise<void> {
    await this.db.run('INSERT OR REPLACE INTO outbox (seq, json) VALUES (?, ?)', [op.seq, JSON.stringify(op)]);
    await this.flush();
  }

  async deleteOp(seq: number): Promise<void> {
    await this.db.run('DELETE FROM outbox WHERE seq = ?', [seq]);
    await this.flush();
  }

  async setMeta(key: string, value: string | null): Promise<void> {
    if (null === value) await this.db.run('DELETE FROM meta WHERE key = ?', [key]);
    else await this.db.run('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', [key, value]);
    this.persistWeb();
  }

  async clear(): Promise<void> {
    await this.db.execute('DELETE FROM docs; DELETE FROM outbox; DELETE FROM meta;');
    this.persistWeb();
  }

  async flush(): Promise<void> {
    if (!this.web) return;
    if (this.saveTimer) clearTimeout(this.saveTimer);
    this.saveTimer = null;
    await this.sqlite.saveToStore(DB_NAME);
  }

  /** jeep-sqlite keeps the database in memory: flush it to IndexedDB (debounced). */
  private persistWeb(): void {
    if (!this.web) return;
    if (this.saveTimer) clearTimeout(this.saveTimer);
    this.saveTimer = setTimeout(() => void this.sqlite.saveToStore(DB_NAME), 300);
  }
}
