import { tokenStorage } from './tokenStorage';

const baseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api';

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    public readonly detail: string,
    /** Extra problem+json members, e.g. `reservedBy` on reservation.already_reserved. */
    public readonly extra: Record<string, unknown> = {},
  ) {
    super(detail);
  }
}

/** Thrown when the refresh token itself is invalid/expired: caller must sign the user out. */
export class SessionExpiredError extends Error {}

interface RequestOptions {
  json?: unknown;
  formData?: FormData;
  auth?: boolean;
  /**
   * Send X-Acting-As for the active child profile (spec §5.15)? On by
   * default; off for what the manager always does in their own name
   * (interactions, « Mes enfants », their own account).
   */
  acting?: boolean;
}

let actingAsProfileId: string | null = null;

/** Set by stores/activeProfile: the child profile requests act as, or null. */
export function setActingAs(profileId: string | null): void {
  actingAsProfileId = profileId;
}

let refreshPromise: Promise<void> | null = null;

async function doRefresh(): Promise<void> {
  const refreshToken = await tokenStorage.getRefreshToken();
  if (!refreshToken) {
    throw new SessionExpiredError();
  }

  const response = await fetch(`${baseUrl}/auth/refresh`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ refresh_token: refreshToken }),
  });

  if (!response.ok) {
    await tokenStorage.clear();
    throw new SessionExpiredError();
  }

  const data = await response.json();
  await tokenStorage.setTokens(data.token, data.refresh_token);
}

async function rawRequest<T>(method: string, path: string, options: RequestOptions): Promise<T> {
  // application/ld+json, not application/json: API Platform's plain-JSON
  // format drops @id (and wraps collections as a bare array instead of
  // {member: [...]}) — this app relies on @id to address items for
  // PATCH/DELETE, so it needs the full JSON-LD/Hydra shape.
  const headers: Record<string, string> = { Accept: 'application/ld+json' };
  let body: BodyInit | undefined;

  if (options.formData) {
    body = options.formData;
  } else if (options.json !== undefined) {
    // API Platform's PATCH operations only accept merge-patch+json.
    headers['Content-Type'] = 'PATCH' === method ? 'application/merge-patch+json' : 'application/json';
    body = JSON.stringify(options.json);
  }

  if (options.auth !== false) {
    const token = await tokenStorage.getAccessToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
    if (actingAsProfileId && options.acting !== false) {
      headers['X-Acting-As'] = actingAsProfileId;
    }
  }

  const response = await fetch(`${baseUrl}${path}`, { method, headers, body });

  if (response.status === 204) {
    return undefined as T;
  }

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(response.status, payload.code ?? 'request.failed', payload.detail ?? response.statusText, payload);
  }

  return payload as T;
}

/**
 * Retries once, after a single shared token refresh, on a 401 caused
 * by an expired access token — never on the first request of a
 * refresh itself (avoids infinite recursion).
 */
async function request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
  try {
    return await rawRequest<T>(method, path, options);
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 401 || options.auth === false || path === '/auth/refresh') {
      throw error;
    }

    refreshPromise ??= doRefresh().finally(() => {
      refreshPromise = null;
    });
    await refreshPromise;

    return rawRequest<T>(method, path, options);
  }
}

export const api = {
  get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, options),
  post: <T>(path: string, options?: RequestOptions) => request<T>('POST', path, options),
  put: <T>(path: string, options?: RequestOptions) => request<T>('PUT', path, options),
  patch: <T>(path: string, options?: RequestOptions) => request<T>('PATCH', path, options),
  delete: <T>(path: string, options?: RequestOptions) => request<T>('DELETE', path, options),
};
