import { Capacitor } from '@capacitor/core';
import { lastApiRequestId } from './api';

const baseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api';
const MAX_PER_SESSION = 20;

let sent = 0;
let lastMessage = '';
let currentRoute = '';

/** Kept by the router: the route name where the error happened (never its path or query). */
export function setErrorRoute(name: string): void {
  currentRoute = name;
}

/**
 * Spec §9 "suivi d'erreurs (app)": uncaught errors go to the backend's
 * structured logs (POST /client-errors), with the last request's id to
 * match them. Best-effort, a few per session, the same error once; never
 * the user's data, only the error itself.
 */
export function reportError(error: unknown, kind = 'error'): void {
  const message = error instanceof Error ? `${error.name}: ${error.message}` : String(error);
  if (!message || message === lastMessage || sent >= MAX_PER_SESSION) return;
  lastMessage = message;
  sent += 1;

  const body = {
    kind,
    message: message.slice(0, 500),
    stack: error instanceof Error ? (error.stack ?? '').slice(0, 4000) : undefined,
    route: currentRoute,
    platform: Capacitor.getPlatform(),
    appVersion: import.meta.env.VITE_APP_VERSION ?? 'dev',
    requestId: lastApiRequestId() ?? undefined,
  };

  void fetch(`${baseUrl}/client-errors`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  }).catch(() => {
    // Offline or server down: nothing more to do.
  });
}

/** For tests. */
export function resetErrorReporter(): void {
  sent = 0;
  lastMessage = '';
  currentRoute = '';
}
