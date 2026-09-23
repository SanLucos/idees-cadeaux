import { defineStore } from 'pinia';
import { api } from '../services/api';
import { tokenStorage } from '../services/tokenStorage';
import { applyLocale } from '../i18n';
import { isNetworkError } from '../services/api';
import { useLocalDb } from '../offline/db';
import { useSync } from '../offline/sync';
import type { User } from '../types/user';

interface LoginResponse {
  token: string;
  refresh_token: string;
  user: { id: string; email: string | null; displayName: string | null; isOnboarded: boolean; locale: string };
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as User | null,
    isBootstrapping: true,
  }),
  getters: {
    isAuthenticated: (state) => null !== state.user,
  },
  actions: {
    /** Called once at app startup: resumes the session from secure storage, if any. */
    async bootstrap(): Promise<void> {
      this.isBootstrapping = true;
      try {
        const accessToken = await tokenStorage.getAccessToken();
        const refreshToken = await tokenStorage.getRefreshToken();
        if (accessToken && refreshToken) {
          await this.fetchMe();
        }
      } catch (e) {
        // Offline start (spec §8): keep the session and the last known profile.
        const cached = useLocalDb().get<User>('user', useLocalDb().meta.me);
        if (isNetworkError(e) && cached) {
          this.user = cached;
          applyLocale(cached.locale);
        } else {
          await tokenStorage.clear();
          this.user = null;
        }
      } finally {
        this.isBootstrapping = false;
      }
    },

    async register(email: string, password: string, locale: string): Promise<void> {
      await api.post('/auth/register', { auth: false, json: { email, password, locale } });
    },

    async verifyEmail(email: string, code: string): Promise<void> {
      await api.post('/auth/verify-email', { auth: false, json: { email, code } });
    },

    async resendVerification(email: string): Promise<void> {
      await api.post('/auth/verify-email/resend', { auth: false, json: { email } });
    },

    async login(email: string, password: string): Promise<void> {
      const response = await api.post<LoginResponse>('/auth/login', { auth: false, json: { email, password } });
      await tokenStorage.setTokens(response.token, response.refresh_token);
      await this.fetchMe();
    },

    async socialLogin(provider: 'google' | 'apple', idToken: string): Promise<void> {
      const response = await api.post<LoginResponse>(`/auth/social/${provider}`, { auth: false, json: { idToken } });
      await tokenStorage.setTokens(response.token, response.refresh_token);
      await this.fetchMe();
    },

    /** Spec §5.15: take over a child profile with the emailed code, then sign in. */
    async acceptInvitation(email: string, code: string, password: string): Promise<void> {
      const response = await api.post<LoginResponse>('/auth/managed-invitation/accept', { auth: false, json: { email, code, password } });
      await tokenStorage.setTokens(response.token, response.refresh_token);
      await this.fetchMe();
    },

    async forgotPassword(email: string): Promise<void> {
      await api.post('/auth/forgot-password', { auth: false, json: { email } });
    },

    async resetPassword(email: string, code: string, newPassword: string): Promise<void> {
      await api.post('/auth/reset-password', { auth: false, json: { email, code, newPassword } });
    },

    async logout(): Promise<void> {
      const refreshToken = await tokenStorage.getRefreshToken();
      try {
        await api.post('/auth/logout', { json: { refresh_token: refreshToken }, acting: false });
      } catch {
        // Best-effort: the local session is cleared either way.
      }
      await tokenStorage.clear();
      this.user = null;
      // Nothing of this account stays on the device.
      await useLocalDb().wipe();
    },

    async fetchMe(): Promise<void> {
      this.user = await api.get<User>('/users/me', { acting: false });
      applyLocale(this.user.locale);
      const db = useLocalDb();
      if (db.meta.me && db.meta.me !== this.user.id) {
        // Another account signed in on this device: start from a clean copy.
        await db.wipe();
      }
      await db.setMeta('me', this.user.id);
    },

    /**
     * Pseudo, birthday, language: applied locally at once and queued
     * (spec §11 décision 35), so it works offline too.
     */
    async updateProfile(patch: Partial<Pick<User, 'displayName' | 'birthDay' | 'birthMonth' | 'birthYear' | 'locale'>>): Promise<void> {
      if (!this.user) return;
      this.user = { ...this.user, ...patch };
      applyLocale(this.user.locale);
      const db = useLocalDb();
      if (db.get('user', this.user.id)) await db.patch('user', this.user.id, patch);
      await useSync().enqueue({
        method: 'PATCH',
        path: '/users/me',
        body: patch,
        actingAs: null,
        entities: [{ type: 'user', id: this.user.id }],
        label: { kind: 'profile.update' },
      });
    },

    async uploadAvatar(file: File): Promise<void> {
      const formData = new FormData();
      formData.append('avatar', file);
      this.user = await api.post<User>('/users/me/avatar', { formData, acting: false });
    },
  },
});
