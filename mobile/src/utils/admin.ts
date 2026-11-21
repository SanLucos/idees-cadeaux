import type { AdminUser } from '../types/admin';

/** Only adults not being deleted can be introduced or made friends (spec §5.17). */
export function isConnectable(user: AdminUser): boolean {
  return 'regular' === user.type && !user.deletionScheduledAt;
}

/** Number of pairs among n people. */
export function pairCount(n: number): number {
  return (n * (n - 1)) / 2;
}

/** i18n keys of the ways an account signs in, in a stable order. */
export function signInKeys(user: Pick<AdminUser, 'signIn'>): string[] {
  const keys: string[] = [];
  if (user.signIn.password) keys.push('password');
  if (user.signIn.emailLink) keys.push('emailLink');
  keys.push(...user.signIn.social);

  return keys;
}
