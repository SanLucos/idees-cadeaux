import { defineStore } from 'pinia';
import { api, isNetworkError, setActingAs } from '../services/api';
import { repo } from '../offline/repo';
import { uuidv7 } from '../utils/uuidv7';
import type { ManagedProfile, ManagerInvitation } from '../types/user';

const STORAGE_KEY = 'ic.activeProfileId';

/** Own account only: "Mes enfants" is never managed on a child's behalf. */
const OWN = { acting: false } as const;

function readStoredId(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}

function storeId(id: string | null): void {
  try {
    if (id) localStorage.setItem(STORAGE_KEY, id);
    else localStorage.removeItem(STORAGE_KEY);
  } catch {
    // A convenience only: the switcher falls back to "me".
  }
}

/**
 * The Profil screen's "Profil actif" (spec §5.15): me, or one of my
 * child profiles. While a child is active, list/profile/friends
 * requests carry X-Acting-As; the server checks it and refuses the rest.
 */
export const useActiveProfileStore = defineStore('activeProfile', {
  state: () => ({
    children: [] as ManagedProfile[],
    activeId: null as string | null,
    /** Children of others I'm invited to manage (spec §11 décision 60). */
    managerInvitations: [] as ManagerInvitation[],
  }),
  getters: {
    active: (state): ManagedProfile | null => state.children.find((c) => c.id === state.activeId) ?? null,
    isActing: (state): boolean => null !== state.activeId,
  },
  actions: {
    async fetchChildren(): Promise<void> {
      try {
        this.children = await api.get<ManagedProfile[]>('/managed-profiles', OWN);
      } catch (e) {
        // Offline: the synced copy (spec §11 décision 33).
        if (!isNetworkError(e)) throw e;
        this.children = repo.children();
      }
      // Restore the last active child if it still exists and isn't being deleted.
      const stored = this.activeId ?? readStoredId();
      const usable = this.children.find((c) => c.id === stored && !c.deletionScheduledAt);
      this.switchTo(usable ? usable.id : null);
    },

    switchTo(id: string | null): void {
      this.activeId = id;
      setActingAs(id);
      storeId(id);
    },

    async create(input: { displayName: string; birthDay: number | null; birthMonth: number | null; parentalConsent: boolean; shareWithManagerFriends: boolean }): Promise<ManagedProfile> {
      const child = await api.post<ManagedProfile>('/managed-profiles', { json: { id: uuidv7(), ...input }, ...OWN });
      this.children.push(child);

      return child;
    },

    async update(id: string, patch: Partial<Pick<ManagedProfile, 'displayName' | 'birthDay' | 'birthMonth' | 'shareWithManagerFriends'>>): Promise<ManagedProfile> {
      return this.replace(await api.patch<ManagedProfile>(`/managed-profiles/${id}`, { json: patch, ...OWN }));
    },

    async uploadAvatar(id: string, file: File): Promise<void> {
      const formData = new FormData();
      formData.append('avatar', file);
      // Written as the child: the avatar is theirs.
      const previous = this.activeId;
      setActingAs(id);
      try {
        await api.post('/users/me/avatar', { formData });
      } finally {
        setActingAs(previous);
      }
      await this.fetchChildren();
    },

    async attachEmail(id: string, email: string): Promise<ManagedProfile> {
      return this.replace(await api.post<ManagedProfile>(`/managed-profiles/${id}/attach-email`, { json: { email }, ...OWN }));
    },

    async scheduleDeletion(id: string): Promise<ManagedProfile> {
      if (this.activeId === id) this.switchTo(null);

      return this.replace(await api.delete<ManagedProfile>(`/managed-profiles/${id}`, OWN));
    },

    async cancelDeletion(id: string): Promise<ManagedProfile> {
      return this.replace(await api.post<ManagedProfile>(`/managed-profiles/${id}/cancel-deletion`, OWN));
    },

    // ---- second manager (spec §11 décision 60)

    async inviteManager(id: string, userId: string): Promise<ManagedProfile> {
      return this.replace(await api.post<ManagedProfile>(`/managed-profiles/${id}/manager-invitation`, { json: { userId }, ...OWN }));
    },

    async cancelManagerInvitation(id: string): Promise<ManagedProfile> {
      return this.replace(await api.delete<ManagedProfile>(`/managed-profiles/${id}/manager-invitation`, OWN));
    },

    /** The other manager, or me: then the child is no longer mine. */
    async removeManager(id: string, managerId: string, me: string): Promise<void> {
      if (managerId === me) {
        await api.delete(`/managed-profiles/${id}/managers/${managerId}`, OWN);
        if (this.activeId === id) this.switchTo(null);
        this.children = this.children.filter((c) => c.id !== id);

        return;
      }
      this.replace(await api.delete<ManagedProfile>(`/managed-profiles/${id}/managers/${managerId}`, OWN));
    },

    async fetchManagerInvitations(): Promise<void> {
      try {
        this.managerInvitations = await api.get<ManagerInvitation[]>('/manager-invitations', OWN);
      } catch (e) {
        if (!isNetworkError(e)) throw e;
      }
    },

    async acceptManagerInvitation(invitationId: string): Promise<ManagedProfile> {
      const child = await api.post<ManagedProfile>(`/manager-invitations/${invitationId}/accept`, { json: { parentalConsent: true }, ...OWN });
      this.managerInvitations = this.managerInvitations.filter((i) => i.id !== invitationId);
      this.children = [...this.children.filter((c) => c.id !== child.id), child];

      return child;
    },

    async declineManagerInvitation(invitationId: string): Promise<void> {
      await api.post(`/manager-invitations/${invitationId}/decline`, OWN);
      this.managerInvitations = this.managerInvitations.filter((i) => i.id !== invitationId);
    },

    replace(child: ManagedProfile): ManagedProfile {
      this.children = this.children.map((c) => (c.id === child.id ? child : c));

      return child;
    },

    /** On logout: never carry a child's header into another session. */
    reset(): void {
      this.children = [];
      this.managerInvitations = [];
      this.switchTo(null);
    },
  },
});
