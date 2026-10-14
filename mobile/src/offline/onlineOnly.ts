import { isWeb } from '../utils/platform';
import { useSync } from './sync';

/**
 * Spec §11 décision 56: the web app works online only. A write attempted
 * offline is refused before anything changes on screen; it surfaces as a
 * network error, i.e. the usual « vous êtes hors ligne » message.
 */
export class WebOfflineError extends TypeError {
  constructor() {
    super('offline');
  }
}

export function assertCanWrite(): void {
  if (isWeb() && !useSync().online) throw new WebOfflineError();
}

/** Each method checks assertCanWrite() first. */
export function onlineOnlyOnWeb<T extends Record<string, (...args: never[]) => Promise<unknown>>>(mutations: T): T {
  return Object.fromEntries(
    Object.entries(mutations).map(([name, fn]) => [
      name,
      // async: the refusal arrives as a rejected promise, like any other failure.
      async (...args: never[]) => {
        assertCanWrite();

        return fn(...args);
      },
    ]),
  ) as T;
}
