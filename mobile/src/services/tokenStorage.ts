import { SecureStorage } from '@aparajita/capacitor-secure-storage';

// Keychain (iOS) / Keystore (Android) via Capacitor, per spec §2 and
// CLAUDE.md "Sécurité" — never localStorage for tokens. The plugin's
// web implementation is used automatically in the browser dev server.
const ACCESS_TOKEN_KEY = 'auth.accessToken';
const REFRESH_TOKEN_KEY = 'auth.refreshToken';

export const tokenStorage = {
  async getAccessToken(): Promise<string | null> {
    return SecureStorage.getItem(ACCESS_TOKEN_KEY);
  },
  async getRefreshToken(): Promise<string | null> {
    return SecureStorage.getItem(REFRESH_TOKEN_KEY);
  },
  async setTokens(accessToken: string, refreshToken: string): Promise<void> {
    await SecureStorage.setItem(ACCESS_TOKEN_KEY, accessToken);
    await SecureStorage.setItem(REFRESH_TOKEN_KEY, refreshToken);
  },
  async clear(): Promise<void> {
    await SecureStorage.removeItem(ACCESS_TOKEN_KEY);
    await SecureStorage.removeItem(REFRESH_TOKEN_KEY);
  },
};
