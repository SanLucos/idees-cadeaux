export interface User {
  id: string;
  email: string | null;
  displayName: string | null;
  avatarUrl: string | null;
  birthDay: number | null;
  birthMonth: number | null;
  birthYear: number | null;
  locale: string;
  isOnboarded: boolean;
  emailVerified: boolean;
  type?: 'regular' | 'managed';
  managedBy?: { id: string; displayName: string | null } | null;
  /** Spec §5.13: set during the 14 days of grace before the account is erased. */
  deletionScheduledAt?: string | null;
  /** Re-authentication: the password, or else a code sent by email. */
  hasPassword?: boolean;
}

/** A child profile (spec §5.15), as listed by GET /managed-profiles. */
export interface ManagedProfile {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  birthDay: number | null;
  birthMonth: number | null;
  birthYear: number | null;
  managedBy: { id: string; displayName: string | null } | null;
  ideaCount: number;
  parentalConsentAt: string | null;
  deletionScheduledAt: string | null;
  invitation: { email: string; expiresAt: string } | null;
}
