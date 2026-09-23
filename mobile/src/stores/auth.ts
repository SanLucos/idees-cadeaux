import { defineStore } from 'pinia';
import { api } from '../services/api';
import { tokenStorage } from '../services/tokenStorage';
import { applyLocale } from '../i18n';
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
      } catch {
        await tokenStorage.clear();
        this.user = null;
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
    },

    async fetchMe(): Promise<void> {
      this.user = await api.get<User>('/users/me', { acting: false });
      applyLocale(this.user.locale);
    },

    async updateProfile(patch: Partial<Pick<User, 'displayName' | 'birthDay' | 'birthMonth' | 'birthYear' | 'locale'>>): Promise<void> {
      this.user = await api.patch<User>('/users/me', { json: patch, acting: false });
      applyLocale(this.user.locale);
    },

    async uploadAvatar(file: File): Promise<void> {
      const formData = new FormData();
      formData.append('avatar', file);
      this.user = await api.post<User>('/users/me/avatar', { formData, acting: false });
    },
  },
});
