import { defineStore } from 'pinia';

const STORAGE_KEY = 'ic.pendingInvitation';
/** Spec §5.16: an invitation opened before signing in is kept 7 days. */
export const PENDING_INVITATION_TTL_MS = 7 * 24 * 60 * 60 * 1000;

interface Stored {
  token: string;
  savedAt: number;
}

function read(): Stored | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const value = raw ? (JSON.parse(raw) as Stored) : null;

    return value && typeof value.token === 'string' && typeof value.savedAt === 'number' ? value : null;
  } catch {
    return null;
  }
}

function write(value: Stored | null): void {
  try {
    if (value) localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    else localStorage.removeItem(STORAGE_KEY);
  } catch {
    // Kept in memory for this session only.
  }
}

/**
 * A share link waiting for its confirmation screen (spec §5.16): opened
 * signed out (kept through sign-up, email check and onboarding), or
 * offline (handled when the network is back). On the device only —
 * the server only ever sees the token when the user confirms.
 */
export const usePendingInvitationStore = defineStore('pendingInvitation', {
  state: () => ({
    stored: read(),
    /** Put aside while offline: not reopened until the network is back. */
    deferred: false,
  }),
  getters: {
    token(state): string | null {
      if (!state.stored || Date.now() - state.stored.savedAt > PENDING_INVITATION_TTL_MS) return null;

      return state.stored.token;
    },
  },
  actions: {
    keep(token: string): void {
      this.deferred = false;
      if (this.stored?.token === token) return;
      this.stored = { token, savedAt: Date.now() };
      write(this.stored);
    },
    clear(): void {
      this.stored = null;
      this.deferred = false;
      write(null);
    },
    defer(): void {
      this.deferred = true;
    },
    resume(): void {
      this.deferred = false;
    },
  },
});
