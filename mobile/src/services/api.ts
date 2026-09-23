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
  /** Extra headers (e.g. Idempotency-Key). */
  headers?: Record<string, string>;
  /** An explicit X-Acting-As, overriding the active profile (outbox replays). */
  actingAs?: string | null;
}

/** Raw outcome of a request that reached the server (see api.send). */
export interface SendResult {
  status: number;
  payload: Record<string, unknown>;
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

async function perform(method: string, path: string, options: RequestOptions): Promise<SendResult> {
  // application/ld+json, not application/json: API Platform's plain-JSON
  // format drops @id (and wraps collections as a bare array instead of
  // {member: [...]}) — this app relies on @id to address items for
  // PATCH/DELETE, so it needs the full JSON-LD/Hydra shape.
  const headers: Record<string, string> = { Accept: 'application/ld+json', ...options.headers };
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
    const actingAs = undefined !== options.actingAs ? options.actingAs : options.acting !== false ? actingAsProfileId : null;
    if (actingAs) {
      headers['X-Acting-As'] = actingAs;
    }
  }

  // A network failure throws here (TypeError): callers treat it as "offline".
  const response = await fetch(`${baseUrl}${path}`, { method, headers, body });
  const payload = 204 === response.status ? {} : await response.json().catch(() => ({}));

  return { status: response.status, payload };
}

async function rawRequest<T>(method: string, path: string, options: RequestOptions): Promise<T> {
  const { status, payload } = await perform(method, path, options);

  if (status >= 400) {
    throw new ApiError(status, (payload.code as string) ?? 'request.failed', (payload.detail as string) ?? '', payload);
  }

  return (204 === status ? undefined : payload) as T;
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

/**
 * Like request(), but a 4xx/5xx answer is returned, not thrown: the
 * outbox needs to tell "the server said no" from "no network" (which
 * still throws). Refreshes an expired token once.
 */
async function send(method: string, path: string, options: RequestOptions = {}): Promise<SendResult> {
  const result = await perform(method, path, options);
  if (401 !== result.status || options.auth === false) return result;

  refreshPromise ??= doRefresh().finally(() => {
    refreshPromise = null;
  });
  await refreshPromise;

  return perform(method, path, options);
}

/** True when an error means "couldn't reach the server" rather than "the server refused". */
export function isNetworkError(error: unknown): boolean {
  return error instanceof TypeError;
}

export const api = {
  send,
  get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, options),
  post: <T>(path: string, options?: RequestOptions) => request<T>('POST', path, options),
  put: <T>(path: string, options?: RequestOptions) => request<T>('PUT', path, options),
  patch: <T>(path: string, options?: RequestOptions) => request<T>('PATCH', path, options),
  delete: <T>(path: string, options?: RequestOptions) => request<T>('DELETE', path, options),
};
