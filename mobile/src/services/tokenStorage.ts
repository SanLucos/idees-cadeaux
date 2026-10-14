import { SecureStorage } from '@aparajita/capacitor-secure-storage';
import { isWeb } from '../utils/platform';

// Keychain (iOS) / Keystore (Android) via Capacitor, per spec §2 and
// CLAUDE.md "Sécurité" — never localStorage for tokens. The plugin's
// web implementation is used automatically in the browser dev server.
const ACCESS_TOKEN_KEY = 'auth.accessToken';
const REFRESH_TOKEN_KEY = 'auth.refreshToken';

// The web app (spec §11 décision 56): the short-lived access token stays in
// memory only, the refresh token in an httpOnly cookie the browser sends to
// /api/auth by itself — neither is ever written to the browser's storage.
let webAccessToken: string | null = null;

export const tokenStorage = {
  async getAccessToken(): Promise<string | null> {
    return isWeb() ? webAccessToken : SecureStorage.getItem(ACCESS_TOKEN_KEY);
  },
  async getRefreshToken(): Promise<string | null> {
    return isWeb() ? null : SecureStorage.getItem(REFRESH_TOKEN_KEY);
  },
  /** `refreshToken` is absent on the web: the server set it as a cookie. */
  async setTokens(accessToken: string, refreshToken?: string): Promise<void> {
    if (isWeb()) {
      webAccessToken = accessToken;
      return;
    }
    await SecureStorage.setItem(ACCESS_TOKEN_KEY, accessToken);
    if (refreshToken) await SecureStorage.setItem(REFRESH_TOKEN_KEY, refreshToken);
  },
  async clear(): Promise<void> {
    if (isWeb()) {
      webAccessToken = null;
      return;
    }
    await SecureStorage.removeItem(ACCESS_TOKEN_KEY);
    await SecureStorage.removeItem(REFRESH_TOKEN_KEY);
  },
};
