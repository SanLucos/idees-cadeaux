/** A synced document: an API view plus its sync version. */
export type Doc = Record<string, any> & { id: string; _version?: string }; // eslint-disable-line @typescript-eslint/no-explicit-any

export const DOC_TYPES = [
  'user',
  'managed_profile',
  'friendship',
  'idea',
  'comment',
  'profile_size',
  'profile_preference',
  'notification',
  'occasion',
] as const;
export type DocType = (typeof DOC_TYPES)[number];

/** A write waiting to reach the server (spec §8 outbox). */
export interface OutboxOp {
  seq: number;
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  path: string;
  body?: unknown;
  /** A picture taken offline (spec §11 décision 34), sent as multipart. */
  upload?: { field: string; dataUrl: string; filename: string };
  /** X-Acting-As at the time of the action (a child profile's write). */
  actingAs: string | null;
  idempotencyKey: string;
  /** Local documents this write touches: kept as-is until it's through. */
  entities: { type: DocType; id: string }[];
  /** For the "pending actions" screen. */
  label: { kind: string; title?: string };
  status: 'pending' | 'failed';
  attempts: number;
  nextAttemptAt: number;
  error?: { status: number; code: string; extra: Record<string, unknown> };
  createdAt: string;
}

export interface LocalSnapshot {
  docs: Partial<Record<DocType, Doc[]>>;
  outbox: OutboxOp[];
  meta: Record<string, string>;
}

/**
 * Durable device storage (spec §8: "base SQLite locale = source de
 * lecture de l'UI"). The app reads a reactive in-memory mirror loaded
 * from here at start (offline/db.ts); every change is written through.
 */
export interface LocalStore {
  load(): Promise<LocalSnapshot>;
  putDocs(type: DocType, docs: Doc[]): Promise<void>;
  deleteDocs(type: DocType, ids: string[]): Promise<void>;
  putOp(op: OutboxOp): Promise<void>;
  deleteOp(seq: number): Promise<void>;
  setMeta(key: string, value: string | null): Promise<void>;
  /** Sign-out: nothing of this account stays on the device (spec §8, §4). */
  clear(): Promise<void>;
  /** Make everything written so far durable now (end of a sync). */
  flush?(): Promise<void>;
}
